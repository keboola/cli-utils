<?php

declare(strict_types=1);

namespace Keboola\Console\Command;

use InvalidArgumentException;
use Keboola\Csv\CsvFile;
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

            $componentIds = array_values(array_unique(array_filter(array_map(
                fn(array $row): ?string => $row['componentId'],
                $rows,
            ))));

            // collect live workspaces (by schema), live configs and trashed configs across all branches
            /** @var array<string, array{workspaceId: string, branchId: int, branchName: string, loginType: string, componentId: string, configurationId: string}> $liveWorkspacesBySchema */
            $liveWorkspacesBySchema = [];
            /** @var array<string, true> $liveConfigs */
            $liveConfigs = [];
            /** @var array<string, true> $trashedConfigs */
            $trashedConfigs = [];

            $devBranches = new DevBranches($storageClient);
            foreach ($devBranches->listBranches() as $branch) {
                $branchId = $branch['id'];
                assert(is_int($branchId));
                $branchName = $branch['name'];
                assert(is_string($branchName));
                $branchClient = new BranchAwareClient($branchId, [
                    'token' => $storageToken['token'],
                    'url' => $connectionUrl,
                ]);

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
                            if ($isDeleted) {
                                $trashedConfigs[$key] = true;
                            } else {
                                $liveConfigs[$key] = true;
                            }
                        }
                    }
                }
            }

            foreach ($rows as $row) {
                if (isset($liveWorkspacesBySchema[$row['schema']])) {
                    $live = $liveWorkspacesBySchema[$row['schema']];
                    $note = '';
                    if ($row['configurationId'] !== null && $row['configurationId'] !== $live['configurationId']) {
                        $note = sprintf('configurationId differs from expected "%s"', $row['configurationId']);
                    }
                    $this->writeReportRow($report, $statusCounts, $projectId, $row, self::STATUS_LIVE, $live, $note);
                    continue;
                }
                if ($row['componentId'] === null || $row['configurationId'] === null) {
                    $this->writeReportRow($report, $statusCounts, $projectId, $row, self::STATUS_NOT_LIVE_NO_CONFIG_REF);
                    continue;
                }
                $key = $row['componentId'] . '/' . $row['configurationId'];
                if (isset($trashedConfigs[$key])) {
                    $this->writeReportRow($report, $statusCounts, $projectId, $row, self::STATUS_CONFIG_IN_TRASH);
                } elseif (isset($liveConfigs[$key])) {
                    $this->writeReportRow($report, $statusCounts, $projectId, $row, self::STATUS_CONFIG_LIVE_WORKSPACE_GONE);
                } else {
                    $this->writeReportRow($report, $statusCounts, $projectId, $row, self::STATUS_PURGED_OR_ORPHAN);
                }
            }

            $tokensClient = new Tokens($storageClient);
            assert(is_scalar($storageToken['id']));
            $tokensClient->dropToken((int) $storageToken['id']);
        }

        $output->writeln(sprintf('Report of %d workspaces written to "%s":', $totalRows, $outputFile));
        ksort($statusCounts);
        foreach ($statusCounts as $status => $count) {
            $output->writeln(sprintf(' - %s: %d (%s)', $status, $count, self::SUGGESTED_ACTION[$status]));
        }

        return 0;
    }

    /**
     * @param array<string, int> $statusCounts
     * @param array{schema: string, componentId: string|null, configurationId: string|null} $row
     * @param array{workspaceId: string, branchId: int, branchName: string, loginType: string, componentId: string, configurationId: string}|null $live
     */
    private function writeReportRow(
        CsvFile $report,
        array &$statusCounts,
        string $projectId,
        array $row,
        string $status,
        ?array $live = null,
        string $note = ''
    ): void {
        $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
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
            $note,
        ]);
    }
}
