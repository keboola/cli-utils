<?php

declare(strict_types=1);

namespace Keboola\Console\Command;

use GuzzleHttp\Client as GuzzleClient;
use Keboola\ManageApi\Client;
use Symfony\Component\Console\Command\Command;
use Throwable;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read-only inventory of a stack's storage backends, with a cleanup verdict per backend.
 *
 * Feeds manage:delete-backend — the ID lists printed at the end are ready to paste.
 */
class ListStorageBackends extends Command
{
    private const ARGUMENT_TOKEN = 'token';
    private const ARGUMENT_URL = 'url';
    private const OPTION_FORMAT = 'format';
    private const OPTION_VERDICT = 'verdict';
    private const OPTION_UNSUPPORTED = 'unsupported';

    private const FORMAT_TABLE = 'table';
    private const FORMAT_CSV = 'csv';

    /**
     * Backends we keep running. Anything else is decommissioned (mysql, redshift, synapse,
     * exasol, teradata) or a parked PoC (supabase, removed in DMD-991) and is up for removal.
     */
    private const KEPT_BACKENDS = ['snowflake', 'bigquery'];

    private const VERDICT_DELETE_UNSUPPORTED_UNUSED = 'DELETE_UNSUPPORTED_UNUSED';
    private const VERDICT_DELETE_UNUSED = 'DELETE_UNUSED';
    private const VERDICT_REVIEW_UNSUPPORTED_IN_USE = 'REVIEW_UNSUPPORTED_IN_USE';
    private const VERDICT_REVIEW_MAINTAINER_ONLY = 'REVIEW_MAINTAINER_ONLY';
    private const VERDICT_KEEP = 'KEEP';

    private const COLUMNS = [
        'id',
        'backend',
        'host',
        'region',
        'owner',
        'technicalOwner',
        'projects',
        'maintainers',
        'buckets',
        'loginType',
        'keyRotated',
        'dynBackends',
        'useSso',
        'ssoEnabled',
        'ssoConfigured',
        'created',
        'verdict',
    ];

    protected function configure(): void
    {
        $this
            ->setName('manage:list-backends')
            ->setDescription(
                'Read-only: list all storage backends of a stack with project/maintainer counts '
                . 'and a cleanup verdict. Prints ID lists ready for manage:delete-backend.'
            )
            ->addArgument(self::ARGUMENT_TOKEN, InputArgument::REQUIRED, 'manage api token')
            ->addArgument(self::ARGUMENT_URL, InputArgument::REQUIRED, 'Stack URL')
            ->addOption(
                self::OPTION_FORMAT,
                null,
                InputOption::VALUE_REQUIRED,
                'Output format: table or csv',
                self::FORMAT_TABLE
            )
            ->addOption(
                self::OPTION_VERDICT,
                null,
                InputOption::VALUE_REQUIRED,
                'Show only backends with this verdict (e.g. DELETE_UNSUPPORTED_UNUSED)'
            )
            ->addOption(
                self::OPTION_UNSUPPORTED,
                null,
                InputOption::VALUE_NONE,
                'Show only backends that are not snowflake/bigquery'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token = $input->getArgument(self::ARGUMENT_TOKEN);
        assert(is_string($token));
        $url = $input->getArgument(self::ARGUMENT_URL);
        assert(is_string($url));

        $format = $input->getOption(self::OPTION_FORMAT);
        if (!in_array($format, [self::FORMAT_TABLE, self::FORMAT_CSV], true)) {
            $output->writeln('<error>Unknown format, use table or csv</error>');
            return 1;
        }
        $verdictFilter = $input->getOption(self::OPTION_VERDICT);
        assert($verdictFilter === null || is_string($verdictFilter));
        $unsupportedOnly = (bool) $input->getOption(self::OPTION_UNSUPPORTED);

        $client = new Client(['url' => $url, 'token' => $token]);
        $guzzle = new GuzzleClient([
            'base_uri' => $url,
            'headers' => ['X-KBC-ManageApiToken' => $token],
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);

        $backends = $client->listStorageBackend();
        assert(is_array($backends));

        $progress = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $progress->writeln(sprintf('Fetching details for %d backends...', count($backends)));

        $rows = [];
        $failedDetails = [];
        foreach ($backends as $backend) {
            assert(is_array($backend));
            $detail = $this->fetchDetail($guzzle, $backend);
            if ($detail === []) {
                $failedDetails[] = $this->asInt($backend['id'] ?? 0);
            }
            $rows[] = $this->buildRow(array_merge($backend, $detail));
        }
        usort($rows, fn (array $a, array $b) => $a['id'] <=> $b['id']);

        if ($failedDetails !== []) {
            // Counts and verdicts come from the list response; only the detail-added columns are affected.
            $progress->writeln(sprintf(
                '<error>Warning: detail fetch failed for backend(s) %s — '
                . 'loginType/keyRotated/SSO/dynBackends columns are empty there, not authoritative.</error>',
                implode(',', $failedDetails)
            ));
        }

        $visibleRows = $this->filterRows($rows, $unsupportedOnly, $verdictFilter);

        if ($format === self::FORMAT_CSV) {
            $this->renderCsv($output, $visibleRows);
        } else {
            $this->renderTable($output, $visibleRows);
        }

        $this->renderSummary($output, $rows, $format === self::FORMAT_CSV);

        return 0;
    }

    /**
     * @param array<mixed> $backend
     * @return array<mixed>
     */
    private function fetchDetail(GuzzleClient $guzzle, array $backend): array
    {
        try {
            $response = $guzzle->get(sprintf('manage/storage-backend/%d', $this->asInt($backend['id'] ?? 0)));
            $detail = json_decode((string) $response->getBody(), true);
            return is_array($detail) ? $detail : [];
        } catch (Throwable) {
            // Keep the list row usable even when a single detail call fails.
            return [];
        }
    }

    /**
     * @param array<mixed> $backend
     * @return array{id: int, backend: string, host: string, region: string, owner: string,
     *     technicalOwner: string, projects: int, maintainers: int, buckets: int, loginType: string,
     *     keyRotated: string, dynBackends: string, useSso: string, ssoEnabled: string,
     *     ssoConfigured: string, created: string, verdict: string}
     */
    private function buildRow(array $backend): array
    {
        $stats = $backend['stats'] ?? [];
        assert(is_array($stats));

        $type = $this->asString($backend['backend'] ?? '');
        $projects = $this->asInt($backend['assignedProjectsCount'] ?? 0);
        $maintainers = $this->asInt($backend['assignedMaintainersCount'] ?? 0);

        return [
            'id' => $this->asInt($backend['id'] ?? 0),
            'backend' => $type,
            // BigQuery backends have no host, they report a folderId instead.
            'host' => $this->asString($backend['host'] ?? $backend['folderId'] ?? ''),
            'region' => $this->asString($backend['region'] ?? ''),
            'owner' => $this->asString($backend['owner'] ?? ''),
            'technicalOwner' => $this->asString($backend['technicalOwner'] ?? ''),
            'projects' => $projects,
            'maintainers' => $maintainers,
            'buckets' => $this->asInt($stats['bucketsCount'] ?? 0),
            'loginType' => $this->asString($backend['loginType'] ?? ''),
            'keyRotated' => substr($this->asString($backend['keyPairLastRotatedAt'] ?? ''), 0, 10),
            'dynBackends' => $this->asBool($backend['useDynamicBackends'] ?? null),
            'useSso' => $this->asBool($backend['useSso'] ?? null),
            'ssoEnabled' => $this->asBool($backend['isSsoEnabled'] ?? null),
            'ssoConfigured' => $this->asBool($backend['isSsoConfigured'] ?? null),
            'created' => substr($this->asString($backend['created'] ?? ''), 0, 10),
            'verdict' => $this->resolveVerdict($type, $projects, $maintainers),
        ];
    }

    /**
     * @param array<int, array<string, int|string>> $rows
     * @return array<int, array<string, int|string>>
     */
    private function filterRows(array $rows, bool $unsupportedOnly, ?string $verdict): array
    {
        if ($unsupportedOnly) {
            $rows = array_filter($rows, fn (array $r) => !in_array($r['backend'], self::KEPT_BACKENDS, true));
        }
        if ($verdict !== null) {
            $rows = array_filter($rows, fn (array $r) => $r['verdict'] === $verdict);
        }
        return array_values($rows);
    }

    private function resolveVerdict(string $type, int $projects, int $maintainers): string
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

    /**
     * @param array<int, array<string, int|string>> $rows
     */
    private function renderCsv(OutputInterface $output, array $rows): void
    {
        $output->writeln(implode(',', self::COLUMNS));
        foreach ($rows as $row) {
            $cells = [];
            foreach (self::COLUMNS as $column) {
                $cells[] = '"' . str_replace('"', '""', (string) $row[$column]) . '"';
            }
            $output->writeln(implode(',', $cells));
        }
    }

    /**
     * @param array<int, array<string, int|string>> $rows
     */
    private function renderTable(OutputInterface $output, array $rows): void
    {
        $table = new Table($output);
        $table->setHeaders(self::COLUMNS);
        foreach ($rows as $row) {
            $cells = [];
            foreach (self::COLUMNS as $column) {
                $cells[] = (string) $row[$column];
            }
            $table->addRow($cells);
        }
        $table->render();
    }

    /**
     * @param array<int, array<string, int|string>> $rows
     */
    private function renderSummary(OutputInterface $output, array $rows, bool $toStdErr): void
    {
        // In CSV mode keep stdout parseable — the summary goes to stderr.
        $out = $toStdErr && $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;

        $byType = [];
        $byVerdict = [];
        foreach ($rows as $row) {
            $byType[(string) $row['backend']] = ($byType[(string) $row['backend']] ?? 0) + 1;
            $byVerdict[(string) $row['verdict']] = ($byVerdict[(string) $row['verdict']] ?? 0) + 1;
        }
        arsort($byType);
        arsort($byVerdict);

        $out->writeln('');
        $out->writeln(sprintf('Total backends: %d', count($rows)));
        $out->writeln('');
        $out->writeln('By backend type:');
        foreach ($byType as $type => $count) {
            $out->writeln(sprintf('  %-12s %d', $type, $count));
        }
        $out->writeln('');
        $out->writeln('By verdict:');
        foreach ($byVerdict as $verdict => $count) {
            $out->writeln(sprintf('  %-24s %d', $verdict, $count));
        }

        $out->writeln('');
        $out->writeln('Delete candidates (paste into manage:delete-backend):');
        foreach ([self::VERDICT_DELETE_UNSUPPORTED_UNUSED, self::VERDICT_DELETE_UNUSED] as $verdict) {
            $ids = [];
            foreach ($rows as $row) {
                if ($row['verdict'] === $verdict) {
                    $ids[] = (string) $row['id'];
                }
            }
            $out->writeln('');
            $out->writeln(sprintf('  %s (%d):', $verdict, count($ids)));
            $out->writeln($ids === [] ? '    -' : '    ' . implode(',', $ids));
        }

        $reviewVerdicts = [self::VERDICT_REVIEW_UNSUPPORTED_IN_USE, self::VERDICT_REVIEW_MAINTAINER_ONLY];
        $needsReview = 0;
        foreach ($rows as $row) {
            if (in_array($row['verdict'], $reviewVerdicts, true)) {
                $needsReview++;
            }
        }
        if ($needsReview > 0) {
            $out->writeln('');
            $out->writeln(sprintf(
                '%d backend(s) need a manual look before deletion (REVIEW_* verdicts above).',
                $needsReview
            ));
        }
        $out->writeln('');
        $out->writeln(
            'Note: counts cover live projects only. A backend showing 0 can still be blocked by '
            . 'soft-deleted, not-yet-purged projects — the delete guard will refuse those.'
        );
    }
}
