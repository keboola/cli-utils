<?php

declare(strict_types=1);

namespace Keboola\Console\Command;

/**
 * Pure row/verdict/filter logic behind manage:list-backends, kept free of I/O
 * so the cleanup policy can be unit-tested at its boundary cases.
 */
class StorageBackendInventory
{
    /**
     * Backends we keep running. Anything else is decommissioned (mysql, redshift, synapse,
     * exasol, teradata) or a parked PoC (supabase, removed in DMD-991) and is up for removal.
     */
    public const KEPT_BACKENDS = ['snowflake', 'bigquery'];

    public const VERDICT_DELETE_UNSUPPORTED_UNUSED = 'DELETE_UNSUPPORTED_UNUSED';
    public const VERDICT_DELETE_UNUSED = 'DELETE_UNUSED';
    public const VERDICT_REVIEW_UNSUPPORTED_IN_USE = 'REVIEW_UNSUPPORTED_IN_USE';
    public const VERDICT_REVIEW_MAINTAINER_ONLY = 'REVIEW_MAINTAINER_ONLY';
    public const VERDICT_KEEP = 'KEEP';

    public const VERDICTS = [
        self::VERDICT_DELETE_UNSUPPORTED_UNUSED,
        self::VERDICT_DELETE_UNUSED,
        self::VERDICT_REVIEW_UNSUPPORTED_IN_USE,
        self::VERDICT_REVIEW_MAINTAINER_ONLY,
        self::VERDICT_KEEP,
    ];

    public const DELETE_VERDICTS = [
        self::VERDICT_DELETE_UNSUPPORTED_UNUSED,
        self::VERDICT_DELETE_UNUSED,
    ];

    public const REVIEW_VERDICTS = [
        self::VERDICT_REVIEW_UNSUPPORTED_IN_USE,
        self::VERDICT_REVIEW_MAINTAINER_ONLY,
    ];

    /**
     * Builds a row from a list-response entry. Returns null when the entry has no usable id —
     * a 0 id would otherwise leak into sort keys, output and detail calls.
     *
     * @param array<mixed> $backend
     * @return array{id: int, backend: string, host: string, region: string, owner: string,
     *     technicalOwner: string, projects: int, maintainers: int, buckets: int, loginType: string,
     *     keyRotated: string, dynBackends: string, useSso: string, ssoEnabled: string,
     *     ssoConfigured: string, created: string, verdict: string}|null
     */
    public function buildRow(array $backend): ?array
    {
        if (!isset($backend['id']) || !is_numeric($backend['id']) || (int) $backend['id'] <= 0) {
            return null;
        }

        $stats = $backend['stats'] ?? [];
        if (!is_array($stats)) {
            $stats = [];
        }

        $type = $this->asString($backend['backend'] ?? '');
        $projects = $this->asInt($backend['assignedProjectsCount'] ?? 0);
        $maintainers = $this->asInt($backend['assignedMaintainersCount'] ?? 0);

        return [
            'id' => (int) $backend['id'],
            'backend' => $type,
            // BigQuery backends have no host, they report a folderId instead.
            'host' => $this->asString($backend['host'] ?? $backend['folderId'] ?? ''),
            'region' => $this->asString($backend['region'] ?? ''),
            'owner' => $this->asString($backend['owner'] ?? ''),
            'technicalOwner' => $this->asString($backend['technicalOwner'] ?? ''),
            'projects' => $projects,
            'maintainers' => $maintainers,
            'buckets' => $this->asInt($stats['bucketsCount'] ?? 0),
            'loginType' => '',
            'keyRotated' => '',
            'dynBackends' => '',
            'useSso' => '',
            'ssoEnabled' => '',
            'ssoConfigured' => '',
            'created' => substr($this->asString($backend['created'] ?? ''), 0, 10),
            'verdict' => $this->resolveVerdict($type, $projects, $maintainers),
        ];
    }

    /**
     * Fills the detail-only columns from a detail response. Counts and verdict always come
     * from the list response, so a missing/failed detail cannot change what gets deleted.
     *
     * @param array<string, int|string> $row
     * @param array<mixed> $detail
     * @return array<string, int|string>
     */
    public function applyDetail(array $row, array $detail): array
    {
        $row['loginType'] = $this->asString($detail['loginType'] ?? '');
        $row['keyRotated'] = substr($this->asString($detail['keyPairLastRotatedAt'] ?? ''), 0, 10);
        $row['dynBackends'] = $this->asBool($detail['useDynamicBackends'] ?? null);
        $row['useSso'] = $this->asBool($detail['useSso'] ?? null);
        $row['ssoEnabled'] = $this->asBool($detail['isSsoEnabled'] ?? null);
        $row['ssoConfigured'] = $this->asBool($detail['isSsoConfigured'] ?? null);
        return $row;
    }

    /**
     * @param array<int, array<string, int|string>> $rows
     * @return array<int, array<string, int|string>>
     */
    public function filterRows(array $rows, bool $unsupportedOnly, ?string $verdict): array
    {
        if ($unsupportedOnly) {
            $rows = array_filter($rows, fn (array $r) => !in_array($r['backend'], self::KEPT_BACKENDS, true));
        }
        if ($verdict !== null) {
            $rows = array_filter($rows, fn (array $r) => $r['verdict'] === $verdict);
        }
        return array_values($rows);
    }

    public function resolveVerdict(string $type, int $projects, int $maintainers): string
    {
        $isUnsupported = !in_array($type, self::KEPT_BACKENDS, true);
        $isUnused = $projects === 0 && $maintainers === 0;

        if ($isUnsupported) {
            return $isUnused ? self::VERDICT_DELETE_UNSUPPORTED_UNUSED : self::VERDICT_REVIEW_UNSUPPORTED_IN_USE;
        }
        if ($isUnused) {
            return self::VERDICT_DELETE_UNUSED;
        }
        if ($projects === 0) {
            // No projects, but a maintainer still points at it as its default backend.
            return self::VERDICT_REVIEW_MAINTAINER_ONLY;
        }
        return self::VERDICT_KEEP;
    }

    /**
     * @param array<int, array<string, int|string>> $rows
     * @return array{byType: array<string, int>, byVerdict: array<string, int>,
     *     deleteIds: array<string, array<int, string>>, needsReview: int}
     */
    public function summarize(array $rows): array
    {
        $byType = [];
        $byVerdict = [];
        foreach ($rows as $row) {
            $byType[(string) $row['backend']] = ($byType[(string) $row['backend']] ?? 0) + 1;
            $byVerdict[(string) $row['verdict']] = ($byVerdict[(string) $row['verdict']] ?? 0) + 1;
        }
        arsort($byType);
        arsort($byVerdict);

        $deleteIds = [];
        foreach (self::DELETE_VERDICTS as $verdict) {
            $deleteIds[$verdict] = [];
            foreach ($rows as $row) {
                if ($row['verdict'] === $verdict) {
                    $deleteIds[$verdict][] = (string) $row['id'];
                }
            }
        }

        $needsReview = 0;
        foreach ($rows as $row) {
            if (in_array($row['verdict'], self::REVIEW_VERDICTS, true)) {
                $needsReview++;
            }
        }

        return [
            'byType' => $byType,
            'byVerdict' => $byVerdict,
            'deleteIds' => $deleteIds,
            'needsReview' => $needsReview,
        ];
    }

    private function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function asBool(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        return $value ? '1' : '0';
    }
}
