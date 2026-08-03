<?php

declare(strict_types=1);

namespace Keboola\Console\Command;

use InvalidArgumentException;
use Keboola\Csv\CsvFile;
use Keboola\JobQueueClient\Client as JobQueueClient;
use Keboola\JobQueueClient\DTO\Job;
use Keboola\JobQueueClient\ListJobsOptions;
use Keboola\ManageApi\Client as ManageClient;
use Keboola\ServiceClient\ServiceClient;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client as StorageApiClient;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\DevBranches;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use Keboola\StorageApi\Tokens;
use Keboola\StorageApi\Workspaces;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CheckProjectWorkspacesState extends Command
{
    private const ARGUMENT_MANAGE_TOKEN = 'manage-token';
    private const ARGUMENT_SOURCE_FILE = 'source-file';
    private const ARGUMENT_OUTPUT_FILE = 'output-file';
    private const ARGUMENT_HOSTNAME_SUFFIX = 'hostname-suffix';

    private const STATUS_LIVE = 'live';
    private const STATUS_CONFIG_LIVE_WORKSPACE_GONE = 'config_live_workspace_gone';
    private const STATUS_CONFIG_IN_TRASH = 'config_in_trash';
    private const STATUS_PURGED_OR_ORPHAN = 'purged_or_orphan';
    private const STATUS_NOT_LIVE_NO_CONFIG_REF = 'not_live_no_config_ref';
    private const STATUS_ACCESS_DENIED = 'access_denied';

    // config existence of a LIVE workspace's own component/configuration reference
    private const CONFIG_STATE_LIVE = 'live';
    private const CONFIG_STATE_IN_TRASH = 'in_trash';
    private const CONFIG_STATE_NOT_FOUND = 'not_found';

    private const SUGGESTED_ACTION = [
        self::STATUS_LIVE => 'manage:delete-project-workspaces-by-id',
        self::STATUS_CONFIG_LIVE_WORKSPACE_GONE => 'investigate: config lives without this workspace, backend user is likely orphaned',
        self::STATUS_CONFIG_IN_TRASH => 'purge configuration from trash (deleteConfiguration on trashed config)',
        self::STATUS_PURGED_OR_ORPHAN => 'drop backend user (storage:workspace:drop-failed-workspaces-from-metadata or manual)',
        self::STATUS_NOT_LIVE_NO_CONFIG_REF => 'investigate: not live and no componentId/configId reference to check',
        self::STATUS_ACCESS_DENIED => 'grant manage token access to project and re-run',
    ];

    protected function configure(): void
    {
        $this
            ->setName('manage:check-project-workspaces-state')
            ->setDescription(
                'Read-only check of workspaces state: live / config in trash / purged. '
                . 'Classifies each row and suggests the matching cleanup action.'
            )
            ->addArgument(
                self::ARGUMENT_MANAGE_TOKEN,
                InputArgument::REQUIRED,
                'Manage API token (super admin) used to create short-lived project storage tokens.'
            )
            ->addArgument(
                self::ARGUMENT_SOURCE_FILE,
                InputArgument::REQUIRED,
                'Source csv with "projectId,workspaceSchema[,componentId,configurationId]" columns and no header.'
            )
            ->addArgument(
                self::ARGUMENT_OUTPUT_FILE,
                InputArgument::REQUIRED,
                'File to output the csv report to.'
            )
            ->addArgument(
                self::ARGUMENT_HOSTNAME_SUFFIX,
                InputArgument::OPTIONAL,
                'Keboola Connection Hostname Suffix',
                'keboola.com'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manageToken = $input->getArgument(self::ARGUMENT_MANAGE_TOKEN);
        assert(is_string($manageToken));
        $sourceFile = $input->getArgument(self::ARGUMENT_SOURCE_FILE);
        assert(is_string($sourceFile));
        $outputFile = $input->getArgument(self::ARGUMENT_OUTPUT_FILE);
        assert(is_string($outputFile));
        $hostnameSuffix = $input->getArgument(self::ARGUMENT_HOSTNAME_SUFFIX);
        assert(is_string($hostnameSuffix));
        assert($hostnameSuffix !== '');

        $serviceClient = new ServiceClient($hostnameSuffix);
        $connectionUrl = $serviceClient->getConnectionServiceUrl();
        $manageClient = new ManageClient(['token' => $manageToken, 'url' => $connectionUrl]);

        /** @var array<string, array<int, array{schema: string, componentId: string|null, configurationId: string|null}>> $map */
        $map = [];
        $totalRows = 0;
        $csv = new CsvFile($sourceFile);
        foreach ($csv as $line) {
            assert(is_array($line));
            if (count($line) !== 2 && count($line) !== 4) {
                throw new InvalidArgumentException(
                    'File must contain two or four columns (projectId,workspaceSchema[,componentId,configurationId]).'
                );
            }
            $projectId = $line[0];
            $schema = $line[1];
            assert(is_string($projectId) || is_numeric($projectId));
            assert(is_string($schema));
            if (!is_numeric($projectId)) {
                throw new InvalidArgumentException(sprintf('Project id "%s" is not numeric.', $projectId));
            }
            if (!str_starts_with($schema, 'WORKSPACE_')) {
                throw new InvalidArgumentException(sprintf('Workspace schema "%s" does not start with "WORKSPACE_".', $schema));
            }
            $componentId = null;
            $configurationId = null;
            if (count($line) === 4) {
                assert(is_string($line[2]));
                assert(is_string($line[3]) || is_numeric($line[3]));
                $componentId = $line[2] !== '' ? $line[2] : null;
                $configurationId = (string) $line[3] !== '' ? (string) $line[3] : null;
            }
            $map[(string) $projectId][] = [
                'schema' => $schema,
                'componentId' => $componentId,
                'configurationId' => $configurationId,
            ];
            $totalRows++;
        }
        $output->writeln(sprintf('Loaded %d workspaces in %d projects from "%s".', $totalRows, count($map), $sourceFile));

        $report = new CsvFile($outputFile);
        $report->writeRow([
            'projectId',
            'workspaceSchema',
            'componentId',
            'configurationId',
            'status',
            'suggestedAction',
            'workspaceId',
            'branchId',
            'branchName',
            'loginType',
            'liveComponentId',
            'liveConfigurationId',
            'configState',
            'configName',
            'configCreated',
            'configCreator',
            'lastJobStatus',
            'lastJobCreated',
            'lastJobEnd',
            'note',
        ]);

        /** @var array<string, int> $statusCounts */
        $statusCounts = [];

        foreach ($map as $projectId => $rows) {
            $projectId = (string) $projectId;
            $output->writeln(sprintf('Checking project "%s" (%d workspaces).', $projectId, count($rows)));
            try {
                $storageToken = $manageClient->createProjectStorageToken(
                    (int) $projectId,
                    [
                        'description' => 'Read-only workspace state check',
                        'expiresIn' => 1800,
                        // reading component configurations (incl. trash listing) is not
                        // allowed for a minimal token
                        'canManageBuckets' => true,
                    ]
                );
            } catch (\Throwable $e) {
                if ($e->getCode() === 403) {
                    $output->writeln(sprintf('<error>Access denied to project "%s".</error>', $projectId));
                    foreach ($rows as $row) {
                        $this->writeReportRow($report, $statusCounts, $projectId, $row, self::STATUS_ACCESS_DENIED);
                    }
                    continue;
                }
                throw $e;
            }
            assert(is_string($storageToken['token']));

            $storageClient = new StorageApiClient([
                'token' => $storageToken['token'],
                'url' => $connectionUrl,
            ]);

            // collect live workspaces (by schema) across all branches first, so that the
            // config listing below also covers components referenced only by live workspaces
            /** @var array<string, array{workspaceId: string, branchId: int, branchName: string, loginType: string, componentId: string, configurationId: string}> $liveWorkspacesBySchema */
            $liveWorkspacesBySchema = [];
            /** @var array<int, BranchAwareClient> $branchClients */
            $branchClients = [];

            $devBranches = new DevBranches($storageClient);
            $branches = $devBranches->listBranches();
            $defaultBranchId = null;
            /** @var array<int, string> $branchNames */
            $branchNames = [];
            foreach ($branches as $branch) {
                assert(is_int($branch['id']));
                assert(is_string($branch['name']));
                $branchNames[$branch['id']] = $branch['name'];
                if (($branch['isDefault'] ?? false) === true) {
                    $defaultBranchId = $branch['id'];
                }
            }
            foreach ($branches as $branch) {
                $branchId = $branch['id'];
                assert(is_int($branchId));
                $branchName = $branch['name'];
                assert(is_string($branchName));
                $branchClient = new BranchAwareClient($branchId, [
                    'token' => $storageToken['token'],
                    'url' => $connectionUrl,
                ]);
                $branchClients[$branchId] = $branchClient;

                $workspacesClient = new Workspaces($branchClient);
                foreach ($workspacesClient->listWorkspaces() as $workspace) {
                    $schema = $workspace['connection']['schema'] ?? $workspace['name'] ?? '';
                    if ($schema === '') {
                        continue;
                    }
                    $liveWorkspacesBySchema[$schema] = [
                        'workspaceId' => (string) $workspace['id'],
                        'branchId' => $branchId,
                        'branchName' => $branchName,
                        'loginType' => $workspace['connection']['loginType'] ?? '',
                        'componentId' => $workspace['component'] ?? '',
                        'configurationId' => $workspace['configurationId'] ?? '',
                    ];
                }
            }

            $componentIds = array_map(
                fn(array $row): ?string => $row['componentId'],
                $rows,
            );
            foreach ($rows as $row) {
                if (isset($liveWorkspacesBySchema[$row['schema']])) {
                    $componentIds[] = $liveWorkspacesBySchema[$row['schema']]['componentId'];
                }
            }
            $componentIds = array_values(array_unique(array_filter($componentIds)));

            // collect live and trashed configs of all referenced components, per branch
            // (a config deleted in one branch may still exist as a copy with the same id
            // in another branch — the states must not be merged across branches)
            /** @var array<string, array<int, array{name: string, created: string, creator: string}>> $liveConfigs */
            $liveConfigs = [];
            /** @var array<string, array<int, array{name: string, created: string, creator: string}>> $trashedConfigs */
            $trashedConfigs = [];

            foreach ($branchClients as $branchId => $branchClient) {
                $components = new Components($branchClient);
                foreach ($componentIds as $componentId) {
                    foreach ([false, true] as $isDeleted) {
                        $configurations = $components->listComponentConfigurations(
                            (new ListComponentConfigurationsOptions())
                                ->setComponentId($componentId)
                                ->setIsDeleted($isDeleted)
                        );
                        assert(is_array($configurations));
                        foreach ($configurations as $configuration) {
                            assert(is_array($configuration));
                            assert(is_scalar($configuration['id']));
                            $key = $componentId . '/' . (string) $configuration['id'];
                            $creatorToken = $configuration['creatorToken'] ?? [];
                            assert(is_array($creatorToken));
                            $detail = [
                                'name' => is_string($configuration['name'] ?? null) ? $configuration['name'] : '',
                                'created' => is_string($configuration['created'] ?? null) ? $configuration['created'] : '',
                                'creator' => is_string($creatorToken['description'] ?? null)
                                    ? $creatorToken['description'] : '',
                            ];
                            if ($isDeleted) {
                                $trashedConfigs[$key][$branchId] = $detail;
                            } else {
                                $liveConfigs[$key][$branchId] = $detail;
                            }
                        }
                    }
                }
            }

            $queueClient = new JobQueueClient($serviceClient->getQueueUrl(), $storageToken['token']);

            // sanity check: verify job search works at all for this project, so that
            // empty lastJob columns can be trusted as "config really has no job history"
            try {
                $anyJobs = $queueClient->listJobs(
                    (new ListJobsOptions())
                        ->setCreatedTimeFrom(new \DateTimeImmutable('2015-01-01T00:00:00+00:00'))
                        ->setSortBy('id')
                        ->setSortOrder(ListJobsOptions::SORT_ORDER_DESC)
                        ->setLimit(1)
                );
                $output->writeln(count($anyJobs) > 0
                    ? sprintf(
                        '  job search OK, most recent job in project: %s (%s, %s)',
                        $anyJobs[0]->component,
                        $anyJobs[0]->status,
                        $anyJobs[0]->createdTime->format(DATE_ATOM)
                    )
                    : '  <comment>job search returned no jobs for the whole project — empty lastJob columns are inconclusive</comment>');
            } catch (\Throwable $e) {
                $output->writeln(sprintf('  <error>job search sanity check failed: %s</error>', $e->getMessage()));
            }

            foreach ($rows as $row) {
                if (isset($liveWorkspacesBySchema[$row['schema']])) {
                    $live = $liveWorkspacesBySchema[$row['schema']];
                    $note = '';
                    if ($row['configurationId'] !== null && $row['configurationId'] !== $live['configurationId']) {
                        $note = sprintf('configurationId differs from expected "%s"', $row['configurationId']);
                    }
                    $configDetail = null;
                    $lastJob = null;
                    if ($live['componentId'] === '' || $live['configurationId'] === '') {
                        $configState = '';
                    } else {
                        $key = $live['componentId'] . '/' . $live['configurationId'];
                        // the config state is evaluated in the workspace's own branch;
                        // copies in other branches only go to the note
                        $eval = $this->evaluateConfig(
                            $liveConfigs[$key] ?? [],
                            $trashedConfigs[$key] ?? [],
                            $live['branchId'],
                            $branchNames
                        );
                        $configState = $eval['state'];
                        $configDetail = $eval['detail'];
                        $note = trim($note . ' ' . $eval['note']);
                        $lastJob = $this->fetchLastJob($queueClient, $live['componentId'], $live['configurationId'], $note);
                    }
                    $this->writeReportRow($report, $statusCounts, $projectId, $row, self::STATUS_LIVE, $live, $note, $configState, $configDetail, $lastJob);
                    continue;
                }
                if ($row['componentId'] === null || $row['configurationId'] === null) {
                    $this->writeReportRow($report, $statusCounts, $projectId, $row, self::STATUS_NOT_LIVE_NO_CONFIG_REF);
                    continue;
                }
                $key = $row['componentId'] . '/' . $row['configurationId'];
                $eval = $this->evaluateConfig(
                    $liveConfigs[$key] ?? [],
                    $trashedConfigs[$key] ?? [],
                    $defaultBranchId,
                    $branchNames
                );
                $note = $eval['note'];
                $lastJob = $this->fetchLastJob($queueClient, $row['componentId'], $row['configurationId'], $note);
                $status = match ($eval['state']) {
                    self::CONFIG_STATE_IN_TRASH => self::STATUS_CONFIG_IN_TRASH,
                    self::CONFIG_STATE_LIVE => self::STATUS_CONFIG_LIVE_WORKSPACE_GONE,
                    default => self::STATUS_PURGED_OR_ORPHAN,
                };
                $this->writeReportRow($report, $statusCounts, $projectId, $row, $status, null, $note, '', $eval['detail'], $lastJob);
            }

            $tokensClient = new Tokens($storageClient);
            assert(is_scalar($storageToken['id']));
            $tokensClient->dropToken((int) $storageToken['id']);
        }

        $output->writeln(sprintf('Report of %d workspaces written to "%s":', $totalRows, $outputFile));
        ksort($statusCounts);
        foreach ($statusCounts as $statusKey => $count) {
            $status = explode(' ', $statusKey)[0];
            $output->writeln(sprintf(' - %s: %d (%s)', $statusKey, $count, self::SUGGESTED_ACTION[$status]));
        }

        return 0;
    }

    /**
     * Evaluates config existence in the given branch; presence in other branches is
     * reported via note only (branches have independent copies under the same id).
     *
     * @param array<int, array{name: string, created: string, creator: string}> $liveIn
     * @param array<int, array{name: string, created: string, creator: string}> $trashedIn
     * @param array<int, string> $branchNames
     * @return array{state: string, detail: array{name: string, created: string, creator: string}|null, note: string}
     */
    private function evaluateConfig(array $liveIn, array $trashedIn, ?int $branchId, array $branchNames): array
    {
        if ($branchId !== null && isset($liveIn[$branchId])) {
            $state = self::CONFIG_STATE_LIVE;
        } elseif ($branchId !== null && isset($trashedIn[$branchId])) {
            $state = self::CONFIG_STATE_IN_TRASH;
        } else {
            $state = self::CONFIG_STATE_NOT_FOUND;
        }

        $detail = null;
        if ($branchId !== null) {
            $detail = $liveIn[$branchId] ?? $trashedIn[$branchId] ?? null;
        }
        if ($detail === null && count($liveIn) > 0) {
            $detail = $liveIn[array_key_first($liveIn)];
        }
        if ($detail === null && count($trashedIn) > 0) {
            $detail = $trashedIn[array_key_first($trashedIn)];
        }

        $otherBranches = [];
        foreach ($liveIn as $otherBranchId => $ignored) {
            if ($otherBranchId !== $branchId) {
                $otherBranches[] = sprintf('live in branch %d (%s)', $otherBranchId, $branchNames[$otherBranchId] ?? '?');
            }
        }
        foreach ($trashedIn as $otherBranchId => $ignored) {
            if ($otherBranchId !== $branchId) {
                $otherBranches[] = sprintf('in trash in branch %d (%s)', $otherBranchId, $branchNames[$otherBranchId] ?? '?');
            }
        }
        $note = count($otherBranches) > 0 ? 'config also ' . implode(', ', $otherBranches) : '';

        return ['state' => $state, 'detail' => $detail, 'note' => $note];
    }

    /**
     * @return array{status: string, created: string, end: string}|null
     */
    private function fetchLastJob(
        JobQueueClient $queueClient,
        string $componentId,
        string $configurationId,
        string &$note
    ): ?array {
        try {
            $jobs = $queueClient->listJobs(
                (new ListJobsOptions())
                    ->setComponents([$componentId])
                    ->setConfigIds([$configurationId])
                    // explicit wide window so a possible server-side default window
                    // does not hide old jobs
                    ->setCreatedTimeFrom(new \DateTimeImmutable('2015-01-01T00:00:00+00:00'))
                    ->setSortBy('id')
                    ->setSortOrder(ListJobsOptions::SORT_ORDER_DESC)
                    ->setLimit(1)
            );
        } catch (\Throwable $e) {
            $note = trim($note . ' last-job lookup failed: ' . $e->getMessage());
            return null;
        }
        if (count($jobs) === 0) {
            return null;
        }
        $job = $jobs[0];
        assert($job instanceof Job);
        return [
            'status' => $job->status,
            'created' => $job->createdTime->format(DATE_ATOM),
            'end' => $job->endTime !== null ? $job->endTime->format(DATE_ATOM) : '',
        ];
    }

    /**
     * @param array<string, int> $statusCounts
     * @param array{schema: string, componentId: string|null, configurationId: string|null} $row
     * @param array{workspaceId: string, branchId: int, branchName: string, loginType: string, componentId: string, configurationId: string}|null $live
     * @param array{name: string, created: string, creator: string}|null $configDetail
     * @param array{status: string, created: string, end: string}|null $lastJob
     */
    private function writeReportRow(
        CsvFile $report,
        array &$statusCounts,
        string $projectId,
        array $row,
        string $status,
        ?array $live = null,
        string $note = '',
        string $configState = '',
        ?array $configDetail = null,
        ?array $lastJob = null
    ): void {
        $statusKey = $configState !== '' ? sprintf('%s (config %s)', $status, $configState) : $status;
        $statusCounts[$statusKey] = ($statusCounts[$statusKey] ?? 0) + 1;
        $report->writeRow([
            $projectId,
            $row['schema'],
            $row['componentId'] ?? '',
            $row['configurationId'] ?? '',
            $status,
            self::SUGGESTED_ACTION[$status],
            $live['workspaceId'] ?? '',
            $live !== null ? (string) $live['branchId'] : '',
            $live['branchName'] ?? '',
            $live['loginType'] ?? '',
            $live['componentId'] ?? '',
            $live['configurationId'] ?? '',
            $configState,
            $configDetail['name'] ?? '',
            $configDetail['created'] ?? '',
            $configDetail['creator'] ?? '',
            $lastJob['status'] ?? '',
            $lastJob['created'] ?? '',
            $lastJob['end'] ?? '',
            $note,
        ]);
    }
}
