<?php

declare(strict_types=1);

namespace Keboola\Console\Command;

use Keboola\Console\Command\FlowMigration\BatchRunner;
use Keboola\Console\Command\FlowMigration\ProjectClientsFactory;
use Keboola\Console\Command\FlowMigration\ProjectResult;
use Keboola\ManageApi\Client as ManageClient;
use Keboola\ServiceClient\ServiceClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Batch driver for the automated keboola.orchestrator -> keboola.flow migration (AJDA-3117).
 * All migration logic lives in the keboola.flow-migration-tool component; this command only
 * creates and supervises its jobs across a list of projects on one stack.
 */
class MigrateOrchestrationsToFlow extends Command
{
    const ARG_TOKEN = 'token';
    const ARG_URL = 'url';
    const ARG_PROJECTS = 'projects';
    const OPT_FORCE = 'force';
    const OPT_PROJECTS_FILE = 'projects-file';
    const OPT_CONCURRENCY = 'concurrency';
    const OPT_POLL_INTERVAL = 'poll-interval';
    const OPT_REPORT = 'report';

    private const ERROR_ONE_PROJECT_SOURCE =
        'Provide exactly one source of project IDs: the <projects> argument or --projects-file';

    private const CSV_HEADER = ['projectId', 'jobId', 'status', 'durationSeconds', 'error'];
    private const CSV_DELIMITER = ';';
    private const CSV_ENCLOSURE = '"';
    // No proprietary escaping (same default as keboola/csv): quotes are doubled, so an API error
    // message containing \" cannot break the row for Excel/Sheets or any RFC-4180 parser.
    private const CSV_ESCAPE = '';

    protected function configure(): void
    {
        $this
            ->setName('manage:migrate-orchestrations-to-flow')
            ->setDescription(
                'Run the automated keboola.orchestrator -> keboola.flow migration for a batch of projects'
            )
            ->addArgument(self::ARG_TOKEN, InputArgument::REQUIRED, 'Manage API token')
            ->addArgument(
                self::ARG_URL,
                InputArgument::REQUIRED,
                'Stack URL, e.g. https://connection.north-europe.azure.keboola.com'
            )
            ->addArgument(
                self::ARG_PROJECTS,
                InputArgument::OPTIONAL,
                'Comma-separated project IDs, or @path/to/file with one ID per line'
            )
            ->addOption(
                self::OPT_FORCE,
                'f',
                InputOption::VALUE_NONE,
                'Run the real migration; without it jobs are created with dryRun: true'
            )
            ->addOption(
                self::OPT_PROJECTS_FILE,
                null,
                InputOption::VALUE_REQUIRED,
                'File with one project ID per line (alternative to @file in the argument)'
            )
            ->addOption(
                self::OPT_CONCURRENCY,
                null,
                InputOption::VALUE_REQUIRED,
                'Max migration jobs in flight at once',
                '10'
            )
            ->addOption(
                self::OPT_POLL_INTERVAL,
                null,
                InputOption::VALUE_REQUIRED,
                'Seconds between job status polls',
                '5'
            )
            ->addOption(
                self::OPT_REPORT,
                null,
                InputOption::VALUE_REQUIRED,
                'CSV report path (default: flow-migration-<stack>-<timestamp>.csv)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token = $input->getArgument(self::ARG_TOKEN);
        assert(is_string($token));
        $url = $input->getArgument(self::ARG_URL);
        assert(is_string($url));
        $force = (bool) $input->getOption(self::OPT_FORCE);

        $hostnameSuffix = $this->hostnameSuffixFromUrl($url);
        if ($hostnameSuffix === null) {
            $output->writeln(sprintf(
                'Invalid stack URL "%s": expected a URL like https://connection.keboola.com',
                $url
            ));
            return 1;
        }

        $projectIds = $this->resolveProjectIds($input, $output);
        if ($projectIds === null) {
            return 1;
        }

        $concurrency = $this->parsePositiveIntOption($input, self::OPT_CONCURRENCY);
        $pollInterval = $this->parsePositiveIntOption($input, self::OPT_POLL_INTERVAL);
        if ($concurrency === null || $pollInterval === null) {
            $output->writeln('Options --concurrency and --poll-interval must be positive integers');
            return 1;
        }

        $reportPath = $this->resolveReportPath($input, $hostnameSuffix);
        $this->printRunNotice($output, $force, count($projectIds), $concurrency, $pollInterval, $reportPath);

        $reportHandle = $this->openReport($reportPath, $output);
        if ($reportHandle === null) {
            return 1;
        }

        $manageClient = new ManageClient(['url' => $url, 'token' => $token]);
        $clientsFactory = new ProjectClientsFactory(
            $manageClient,
            $url,
            (new ServiceClient($hostnameSuffix))->getQueueUrl()
        );

        $runner = new BatchRunner($clientsFactory, $concurrency, $pollInterval);
        $summary = $runner->run(
            $projectIds,
            $force,
            $output,
            function (ProjectResult $result) use ($reportHandle): void {
                $this->appendReportRow($reportHandle, $result);
            }
        );
        fclose($reportHandle);

        $this->printSummary($output, $summary);

        return $summary['failed'] > 0 ? 1 : 0;
    }

    /**
     * Derives the ServiceClient hostname suffix from a full connection URL, e.g.
     * "https://connection.north-europe.azure.keboola.com" -> "north-europe.azure.keboola.com".
     * Returns null when the URL does not look like a stack connection URL.
     *
     * @return non-empty-string|null
     */
    private function hostnameSuffixFromUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || !str_starts_with($host, 'connection.')) {
            return null;
        }
        $suffix = substr($host, strlen('connection.'));

        return $suffix === '' ? null : $suffix;
    }

    /**
     * Resolves the project ID list from exactly one source: the <projects> argument
     * (inline list or @file) or --projects-file. Prints an error and returns null otherwise.
     *
     * @return array<int, string>|null
     */
    private function resolveProjectIds(InputInterface $input, OutputInterface $output): ?array
    {
        $inlineList = $this->optionalStringInput($input->getArgument(self::ARG_PROJECTS));
        $filePath = $this->optionalStringInput($input->getOption(self::OPT_PROJECTS_FILE));

        if ($inlineList !== null && $filePath !== null) {
            $output->writeln(self::ERROR_ONE_PROJECT_SOURCE);
            return null;
        }

        // "@path" in the <projects> argument is shorthand for --projects-file=path.
        if ($inlineList !== null && str_starts_with($inlineList, '@')) {
            $filePath = substr($inlineList, 1);
            $inlineList = null;
        }

        if ($filePath !== null) {
            return $this->readProjectIdsFile($filePath, $output);
        }

        if ($inlineList === null) {
            $output->writeln(self::ERROR_ONE_PROJECT_SOURCE);
            return null;
        }

        return $this->rejectEmptyProjectIds($this->parseProjectIdList($inlineList), $output);
    }

    /**
     * @return non-empty-string|null null for a missing or empty console input value
     */
    private function optionalStringInput(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<int, string>|null
     */
    private function readProjectIdsFile(string $path, OutputInterface $output): ?array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            $output->writeln(sprintf('Cannot read projects file "%s"', $path));
            return null;
        }

        return $this->rejectEmptyProjectIds($this->parseProjectIdsFile($contents), $output);
    }

    /**
     * @param array<int, string>|null $projectIds null when parsing found a non-numeric ID
     * @return array<int, string>|null
     */
    private function rejectEmptyProjectIds(?array $projectIds, OutputInterface $output): ?array
    {
        if ($projectIds === null || $projectIds === []) {
            $output->writeln('Projects list is empty or contains a non-numeric ID');
            return null;
        }

        return $projectIds;
    }

    /**
     * @return array<int, string>|null null when any entry is not a plain non-negative integer
     */
    private function parseProjectIdList(string $raw): ?array
    {
        return $this->validateAndDeduplicate(array_map('trim', explode(',', $raw)));
    }

    /**
     * One ID per line; blank lines and lines starting with "#" are ignored.
     *
     * @return array<int, string>|null null when any remaining line is not a plain non-negative integer
     */
    private function parseProjectIdsFile(string $contents): ?array
    {
        $lines = preg_split('/\R/', $contents);
        $ids = [];
        foreach ($lines === false ? [] : $lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $ids[] = $line;
        }

        return $this->validateAndDeduplicate($ids);
    }

    /**
     * @param array<int, string> $ids
     * @return array<int, string>|null
     */
    private function validateAndDeduplicate(array $ids): ?array
    {
        foreach ($ids as $id) {
            if (!ctype_digit($id)) {
                return null;
            }
        }

        return array_values(array_unique($ids));
    }

    private function parsePositiveIntOption(InputInterface $input, string $name): ?int
    {
        $value = $input->getOption($name);
        if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }

    private function resolveReportPath(InputInterface $input, string $hostnameSuffix): string
    {
        $reportPath = $input->getOption(self::OPT_REPORT);
        if (is_string($reportPath) && $reportPath !== '') {
            return $reportPath;
        }

        return sprintf('flow-migration-%s-%s.csv', $hostnameSuffix, date('Ymd-His'));
    }

    private function printRunNotice(
        OutputInterface $output,
        bool $force,
        int $projectCount,
        int $concurrency,
        int $pollInterval,
        string $reportPath
    ): void {
        if ($force) {
            $output->writeln('Running in FORCE mode: migration jobs run with dryRun: false.');
        } else {
            $output->writeln(
                'Running in dry-run mode: migration jobs run with dryRun: true. Use -f for the real migration.'
            );
            // The usual cli-utils dry-run changes nothing at all - this one still spends a job slot
            // (and on PAYGO stacks, credits) in every eligible project, so say it out loud.
            $output->writeln('NOTE: even in dry-run mode a real keboola.flow-migration-tool job and a real'
                . ' ephemeral storage token are created in every eligible project.');
        }
        $output->writeln(sprintf(
            'Projects: %d, concurrency: %d, poll interval: %d s',
            $projectCount,
            $concurrency,
            $pollInterval
        ));
        $output->writeln(sprintf('Report: %s', $reportPath));
        $output->writeln('');
    }

    /**
     * Opens the CSV report in append mode and writes the header only for a new or empty file, so
     * re-running with the same --report path keeps one continuous, valid CSV.
     *
     * @return resource|null null when the file cannot be opened
     */
    private function openReport(string $reportPath, OutputInterface $output)
    {
        $needsHeader = !is_file($reportPath) || filesize($reportPath) === 0;

        $reportHandle = fopen($reportPath, 'a');
        if ($reportHandle === false) {
            $output->writeln(sprintf('Cannot open report file "%s" for writing', $reportPath));
            return null;
        }

        if ($needsHeader) {
            fputcsv($reportHandle, self::CSV_HEADER, self::CSV_DELIMITER, self::CSV_ENCLOSURE, self::CSV_ESCAPE);
        }

        return $reportHandle;
    }

    /**
     * @param resource $reportHandle
     */
    private function appendReportRow($reportHandle, ProjectResult $result): void
    {
        fputcsv(
            $reportHandle,
            [
                $result->projectId,
                $result->jobId ?? '',
                $result->status,
                $result->durationSeconds !== null ? (string) $result->durationSeconds : '',
                $result->error ?? '',
            ],
            self::CSV_DELIMITER,
            self::CSV_ENCLOSURE,
            self::CSV_ESCAPE
        );
        // Flush per row so an interrupted run still leaves an auditable report.
        fflush($reportHandle);
    }

    /**
     * @param array{
     *     attempted: int,
     *     migrated: int,
     *     migratedWithWarning: int,
     *     skippedNoOrchestrations: int,
     *     skippedDisabled: int,
     *     failed: int
     * } $summary
     */
    private function printSummary(OutputInterface $output, array $summary): void
    {
        $output->writeln('');
        $output->writeln(sprintf(
            "DONE\nProjects attempted: %d\nMigrated (job success): %d\nMigrated with warning: %d\n"
            . "Skipped (no orchestrations): %d\nSkipped (disabled/deleted): %d\nFailed: %d",
            $summary['attempted'],
            $summary['migrated'],
            $summary['migratedWithWarning'],
            $summary['skippedNoOrchestrations'],
            $summary['skippedDisabled'],
            $summary['failed']
        ));
    }
}
