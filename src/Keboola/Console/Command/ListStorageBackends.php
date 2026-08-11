<?php

declare(strict_types=1);

namespace Keboola\Console\Command;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Keboola\ManageApi\Client;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Read-only inventory of a stack's storage backends, with a cleanup verdict per backend.
 *
 * Feeds manage:delete-backend — the ID lists printed at the end are ready to paste.
 * Row/verdict/filter logic lives in StorageBackendInventory; this command does I/O only.
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

    private const DETAIL_RETRIES = 3;

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
        if ($verdictFilter !== null && !in_array($verdictFilter, StorageBackendInventory::VERDICTS, true)) {
            $output->writeln(sprintf(
                '<error>Unknown verdict "%s", use one of: %s</error>',
                $verdictFilter,
                implode(', ', StorageBackendInventory::VERDICTS)
            ));
            return 1;
        }
        $unsupportedOnly = (bool) $input->getOption(self::OPTION_UNSUPPORTED);

        $client = new Client(['url' => $url, 'token' => $token]);
        $inventory = new StorageBackendInventory();

        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $backends = $client->listStorageBackend();
        assert(is_array($backends));

        $rows = [];
        $skipped = 0;
        foreach ($backends as $backend) {
            assert(is_array($backend));
            $row = $inventory->buildRow($backend);
            if ($row === null) {
                $skipped++;
                continue;
            }
            $rows[] = $row;
        }
        usort($rows, fn (array $a, array $b) => $a['id'] <=> $b['id']);
        if ($skipped > 0) {
            $stderr->writeln(sprintf(
                '<error>Warning: skipped %d list entr%s without a usable numeric id.</error>',
                $skipped,
                $skipped === 1 ? 'y' : 'ies'
            ));
        }

        $visibleRows = $inventory->filterRows($rows, $unsupportedOnly, $verdictFilter);

        // Detail (loginType, keyRotated, SSO flags) is fetched only for the rows that will
        // be displayed — verdicts and counts come from the list response alone.
        $visibleRows = $this->fetchDetails($url, $token, $inventory, $visibleRows, $stderr);

        if ($format === self::FORMAT_CSV) {
            $this->renderCsv($output, $visibleRows);
        } else {
            $this->renderTable($output, $visibleRows);
        }

        $this->renderSummary(
            $output,
            $inventory->summarize($visibleRows),
            count($visibleRows),
            count($rows),
            $format === self::FORMAT_CSV
        );

        return 0;
    }

    /**
     * @param array<int, array<string, int|string>> $rows
     * @return array<int, array<string, int|string>>
     */
    private function fetchDetails(
        string $url,
        string $token,
        StorageBackendInventory $inventory,
        array $rows,
        OutputInterface $stderr
    ): array {
        if ($rows === []) {
            return [];
        }

        $stderr->writeln(sprintf('Fetching details for %d backends...', count($rows)));
        $guzzle = $this->createGuzzleClient($url, $token);

        $failed = [];
        foreach ($rows as $i => $row) {
            $detail = [];
            try {
                $response = $guzzle->get(sprintf('manage/storage-backend/%d', $row['id']));
                $decoded = json_decode((string) $response->getBody(), true);
                if (is_array($decoded)) {
                    $detail = $decoded;
                }
            } catch (Throwable) {
                // Keep the row usable even when a single detail call fails.
            }
            if ($detail === []) {
                $failed[] = (string) $row['id'];
            }
            $rows[$i] = $inventory->applyDetail($row, $detail);
        }

        if ($failed !== []) {
            // Counts and verdicts come from the list response; only the detail-added columns are affected.
            $stderr->writeln(sprintf(
                '<error>Warning: detail fetch failed for backend(s) %s — '
                . 'loginType/keyRotated/SSO/dynBackends columns are empty there, not authoritative.</error>',
                implode(',', $failed)
            ));
        }

        return $rows;
    }

    private function createGuzzleClient(string $url, string $token): GuzzleClient
    {
        $stack = HandlerStack::create();
        $stack->push(Middleware::retry(
            function (
                int $retries,
                RequestInterface $request,
                ?ResponseInterface $response = null,
                ?Throwable $e = null
            ): bool {
                if ($retries >= self::DETAIL_RETRIES) {
                    return false;
                }
                if ($e instanceof ConnectException) {
                    return true;
                }
                return $response !== null
                    && ($response->getStatusCode() === 429 || $response->getStatusCode() >= 500);
            },
            fn (int $retries): int => 1000 * $retries
        ));

        return new GuzzleClient([
            'base_uri' => $url,
            'handler' => $stack,
            'headers' => ['X-KBC-ManageApiToken' => $token],
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * @param array<int, array<string, int|string>> $rows
     */
    private function renderCsv(OutputInterface $output, array $rows): void
    {
        $stream = fopen('php://temp', 'r+');
        assert($stream !== false);
        fputcsv($stream, self::COLUMNS, ',', '"', '');
        foreach ($rows as $row) {
            $cells = [];
            foreach (self::COLUMNS as $column) {
                $cells[] = (string) $row[$column];
            }
            fputcsv($stream, $cells, ',', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        assert(is_string($csv));
        $output->write($csv, false, OutputInterface::OUTPUT_RAW);
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
     * @param array{byType: array<string, int>, byVerdict: array<string, int>,
     *     deleteIds: array<string, array<int, string>>, needsReview: int} $summary
     */
    private function renderSummary(
        OutputInterface $output,
        array $summary,
        int $visibleCount,
        int $totalCount,
        bool $toStdErr
    ): void {
        // In CSV mode keep stdout parseable — the summary goes to stderr.
        $out = $toStdErr && $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;

        $out->writeln('');
        if ($visibleCount === $totalCount) {
            $out->writeln(sprintf('Total backends: %d', $totalCount));
        } else {
            $out->writeln(sprintf(
                'Backends shown: %d of %d (filters active — summary and ID lists cover the shown rows only)',
                $visibleCount,
                $totalCount
            ));
        }
        $out->writeln('');
        $out->writeln('By backend type:');
        foreach ($summary['byType'] as $type => $count) {
            $out->writeln(sprintf('  %-12s %d', $type, $count));
        }
        $out->writeln('');
        $out->writeln('By verdict:');
        foreach ($summary['byVerdict'] as $verdict => $count) {
            $out->writeln(sprintf('  %-24s %d', $verdict, $count));
        }

        $out->writeln('');
        $out->writeln('Delete candidates (paste into manage:delete-backend):');
        foreach ($summary['deleteIds'] as $verdict => $ids) {
            $out->writeln('');
            $out->writeln(sprintf('  %s (%d):', $verdict, count($ids)));
            $out->writeln($ids === [] ? '    -' : '    ' . implode(',', $ids));
        }

        if ($summary['needsReview'] > 0) {
            $out->writeln('');
            $out->writeln(sprintf(
                '%d backend(s) need a manual look before deletion (REVIEW_* verdicts above).',
                $summary['needsReview']
            ));
        }
        $out->writeln('');
        $out->writeln(
            'Note: counts cover live projects only. A backend showing 0 can still be blocked by '
            . 'soft-deleted, not-yet-purged projects — the delete guard will refuse those.'
        );
    }
}
