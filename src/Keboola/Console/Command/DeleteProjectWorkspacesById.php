<?php

declare(strict_types=1);

namespace Keboola\Console\Command;

use InvalidArgumentException;
use Keboola\Csv\CsvFile;
use Keboola\ManageApi\Client as ManageClient;
use Keboola\ServiceClient\ServiceClient;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client as StorageApiClient;
use Keboola\StorageApi\ClientException as StorageClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\DevBranches;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use Keboola\StorageApi\Options\Components\ListConfigurationWorkspacesOptions;
use Keboola\StorageApi\Tokens;
use Keboola\StorageApi\WorkspaceLoginType;
use Keboola\StorageApi\Workspaces;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteProjectWorkspacesById extends Command
{
    private const ARGUMENT_MANAGE_TOKEN = 'manage-token';
    private const ARGUMENT_SOURCE_FILE = 'source-file';
    private const ARGUMENT_HOSTNAME_SUFFIX = 'hostname-suffix';
    private const OPTION_FORCE = 'force';
    private const OPTION_ANY_LOGIN_TYPE = 'any-login-type';
    private const OPTION_WITH_CONFIGURATION = 'with-configuration';

    protected function configure(): void
    {
        $this
            ->setName('manage:delete-project-workspaces-by-id')
            ->setDescription(
                'Delete single workspaces by their id (keeps the parent configuration and its other workspaces). '
                . 'By default only workspaces with password login (LEGACY_SERVICE) are deleted.'
            )
            ->addArgument(
                self::ARGUMENT_MANAGE_TOKEN,
                InputArgument::REQUIRED,
                'Manage API token (super admin) used to create short-lived project storage tokens.'
            )
            ->addArgument(
                self::ARGUMENT_SOURCE_FILE,
                InputArgument::REQUIRED,
                'Source csv with "projectId,workspaceId[,expectedSchema]" columns and no header. '
                . 'When expectedSchema is present the workspace schema must match it, otherwise the row is skipped.'
            )
            ->addArgument(
                self::ARGUMENT_HOSTNAME_SUFFIX,
                InputArgument::OPTIONAL,
                'Keboola Connection Hostname Suffix',
                'keboola.com'
            )
            ->addOption(self::OPTION_FORCE, 'f', InputOption::VALUE_NONE, 'Write changes')
            ->addOption(
                self::OPTION_ANY_LOGIN_TYPE,
                null,
                InputOption::VALUE_NONE,
                'Also delete workspaces whose login type is not a password login (key-pair etc.). USE WITH CARE.'
            )
            ->addOption(
                self::OPTION_WITH_CONFIGURATION,
                null,
                InputOption::VALUE_NONE,
                'Delete the whole parent configuration (trash + purge) instead of just the workspace. '
                . 'Refuses configurations that have any other workspace than the one listed in the csv.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manageToken = $input->getArgument(self::ARGUMENT_MANAGE_TOKEN);
        assert(is_string($manageToken));
        $sourceFile = $input->getArgument(self::ARGUMENT_SOURCE_FILE);
        assert(is_string($sourceFile));
        $hostnameSuffix = $input->getArgument(self::ARGUMENT_HOSTNAME_SUFFIX);
        assert(is_string($hostnameSuffix));
        assert($hostnameSuffix !== '');
        $force = (bool) $input->getOption(self::OPTION_FORCE);
        $anyLoginType = (bool) $input->getOption(self::OPTION_ANY_LOGIN_TYPE);
        $withConfiguration = (bool) $input->getOption(self::OPTION_WITH_CONFIGURATION);

        $serviceClient = new ServiceClient($hostnameSuffix);
        $connectionUrl = $serviceClient->getConnectionServiceUrl();
        $manageClient = new ManageClient(['token' => $manageToken, 'url' => $connectionUrl]);

        $output->writeln(sprintf('Fetching workspaces to delete from "%s"', $sourceFile));
        $output->writeln($force
            ? 'Force option is set, doing it for real'
            : 'This is just a dry-run, nothing will be actually deleted');

        /** @var array<string, array<int, array{workspaceId: string, expectedSchema: string|null}>> $map */
        $map = [];
        $totalRows = 0;
        $csv = new CsvFile($sourceFile);
        foreach ($csv as $line) {
            assert(is_array($line));
            if (count($line) !== 2 && count($line) !== 3) {
                throw new InvalidArgumentException('File must contain two or three columns (projectId,workspaceId[,expectedSchema]).');
            }
            $projectId = $line[0];
            $workspaceId = $line[1];
            $expectedSchema = count($line) === 3 ? $line[2] : null;
            assert(is_string($projectId) || is_numeric($projectId));
            assert(is_string($workspaceId) || is_numeric($workspaceId));
            assert($expectedSchema === null || is_string($expectedSchema));
            if (!is_numeric($projectId)) {
                throw new InvalidArgumentException(sprintf('Project id "%s" is not numeric.', $projectId));
            }
            if (!is_numeric($workspaceId)) {
                throw new InvalidArgumentException(sprintf('Workspace id "%s" is not numeric.', $workspaceId));
            }
            if ($expectedSchema !== null && !str_starts_with($expectedSchema, 'WORKSPACE_')) {
                throw new InvalidArgumentException(sprintf('Expected schema "%s" does not start with "WORKSPACE_".', $expectedSchema));
            }
            $map[(string) $projectId][] = [
                'workspaceId' => (string) $workspaceId,
                'expectedSchema' => $expectedSchema,
            ];
            $totalRows++;
        }
        $output->writeln(sprintf('Loaded %d workspaces in %d projects.', $totalRows, count($map)));

        $deleted = 0;
        $failed = 0;
        $skippedLoginType = 0;
        $skippedSchemaMismatch = 0;
        $skippedConfigGuard = 0;
        $notFound = [];

        foreach ($map as $projectId => $rows) {
            $output->writeln(sprintf('Processing project "%s" (%d workspaces).', $projectId, count($rows)));
            try {
                $storageToken = $manageClient->createProjectStorageToken(
                    (int) $projectId,
                    [
                        'description' => 'Mass workspace deletion by workspace id',
                        'expiresIn' => 1800,
                        // deleting a workspace is not allowed for a minimal token
                        'canManageBuckets' => true,
                        // purging a configuration from trash needs an extra permission
                        'canPurgeTrash' => $withConfiguration,
                    ]
                );
            } catch (\Throwable $e) {
                if ($e->getCode() === 403) {
                    $output->writeln(sprintf('<error>Access denied to project "%s", skipping its %d workspaces.</error>', $projectId, count($rows)));
                    $failed += count($rows);
                    continue;
                }
                throw $e;
            }
            assert(is_string($storageToken['token']));

            $storageClient = new StorageApiClient([
                'token' => $storageToken['token'],
                'url' => $connectionUrl,
            ]);

            // index all workspaces of the project (all dev branches) by workspace id,
            // plus live workspace ids per parent configuration (guard for purging trashed configs)
            $workspacesById = [];
            /** @var array<string, array<int, string>> $liveWorkspaceIdsByConfig */
            $liveWorkspaceIdsByConfig = [];
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
                    $workspacesById[(string) $workspace['id']] = [
                        'branchId' => $branchId,
                        'branchName' => $branchName,
                        'workspace' => $workspace,
                    ];
                    if (($workspace['component'] ?? '') !== '' && ($workspace['configurationId'] ?? '') !== '') {
                        $configKey = $branchId . '|' . $workspace['component'] . '/' . $workspace['configurationId'];
                        $liveWorkspaceIdsByConfig[$configKey][] = (string) $workspace['id'];
                    }
                }
            }

            // lazily fetched live/trashed configuration ids per branch + component
            /** @var array<string, array{live: array<string, true>, trashed: array<string, true>}> $configStateCache */
            $configStateCache = [];

            foreach ($rows as $row) {
                $workspaceId = $row['workspaceId'];
                if (!isset($workspacesById[$workspaceId])) {
                    $notFound[] = sprintf('%s (project %s)', $workspaceId, $projectId);
                    continue;
                }
                $found = $workspacesById[$workspaceId];
                $workspace = $found['workspace'];

                $connection = $workspace['connection'];
                $schema = $connection['schema'] ?? $workspace['name'] ?? '';
                $loginType = $connection['loginType'] ?? '';

                $description = sprintf(
                    'workspace "%s" (schema "%s", loginType "%s", configuration %s/%s, branch %s, created %s) in project "%s"',
                    $workspaceId,
                    $schema,
                    $loginType,
                    $workspace['component'] ?? '',
                    $workspace['configurationId'] ?? '',
                    $found['branchName'],
                    $workspace['created'],
                    $projectId,
                );

                if ($row['expectedSchema'] !== null && $row['expectedSchema'] !== $schema) {
                    $output->writeln(sprintf(
                        '<error>SKIP %s: schema does not match expected "%s".</error>',
                        $description,
                        $row['expectedSchema'],
                    ));
                    $skippedSchemaMismatch++;
                    continue;
                }

                if (!$anyLoginType && WorkspaceLoginType::tryFrom($loginType)?->isPasswordLogin() !== true) {
                    $output->writeln(sprintf(
                        '<error>SKIP %s: login type is not a password login. Use --%s to delete it anyway.</error>',
                        $description,
                        self::OPTION_ANY_LOGIN_TYPE,
                    ));
                    $skippedLoginType++;
                    continue;
                }

                $branchClient = new BranchAwareClient($found['branchId'], [
                    'token' => $storageToken['token'],
                    'url' => $connectionUrl,
                ]);

                if ($withConfiguration) {
                    $component = (string) ($workspace['component'] ?? '');
                    $configurationId = (string) ($workspace['configurationId'] ?? '');
                    if ($component === '' || $configurationId === '') {
                        $output->writeln(sprintf(
                            '<error>SKIP %s: workspace has no parent configuration, cannot delete with configuration.</error>',
                            $description,
                        ));
                        $skippedConfigGuard++;
                        continue;
                    }
                    $components = new Components($branchClient);

                    // the configuration may be live, sitting deleted in trash (purge pending,
                    // which is why its workspace and backend user still exist), or fully purged
                    $cacheKey = $found['branchId'] . '|' . $component;
                    if (!isset($configStateCache[$cacheKey])) {
                        $state = ['live' => [], 'trashed' => []];
                        foreach (['live' => false, 'trashed' => true] as $stateKey => $isDeleted) {
                            $configurations = $components->listComponentConfigurations(
                                (new ListComponentConfigurationsOptions())
                                    ->setComponentId($component)
                                    ->setIsDeleted($isDeleted)
                            );
                            assert(is_array($configurations));
                            foreach ($configurations as $configuration) {
                                assert(is_array($configuration));
                                assert(is_scalar($configuration['id']));
                                $state[$stateKey][(string) $configuration['id']] = true;
                            }
                        }
                        $configStateCache[$cacheKey] = $state;
                    }
                    $configState = $configStateCache[$cacheKey];

                    if (isset($configState['live'][$configurationId])) {
                        // live configuration: authoritative sibling check via the API
                        $configWorkspaces = $components->listConfigurationWorkspaces(
                            (new ListConfigurationWorkspacesOptions())
                                ->setComponentId($component)
                                ->setConfigurationId($configurationId)
                        );
                        assert(is_array($configWorkspaces));
                        $configWorkspaceIds = array_map(static function ($configWorkspace): string {
                            assert(is_array($configWorkspace));
                            assert(is_scalar($configWorkspace['id']));
                            return (string) $configWorkspace['id'];
                        }, $configWorkspaces);
                        $deleteCallCount = 2; // trash + purge
                        $actionDescription = sprintf('delete configuration %s/%s (trash + purge)', $component, $configurationId);
                    } elseif (isset($configState['trashed'][$configurationId])) {
                        // trashed configuration: its workspaces cannot be listed anymore,
                        // check against live workspaces of the project referencing it instead
                        $configWorkspaceIds = $liveWorkspaceIdsByConfig[$found['branchId'] . '|' . $component . '/' . $configurationId] ?? [];
                        $deleteCallCount = 1; // already in trash, single delete purges it
                        $actionDescription = sprintf('purge trashed configuration %s/%s', $component, $configurationId);
                    } else {
                        $output->writeln(sprintf(
                            '<error>SKIP %s: configuration %s/%s is neither live nor in trash — orphaned workspace, delete it without --%s.</error>',
                            $description,
                            $component,
                            $configurationId,
                            self::OPTION_WITH_CONFIGURATION,
                        ));
                        $skippedConfigGuard++;
                        continue;
                    }

                    if ($configWorkspaceIds !== [$workspaceId]) {
                        $output->writeln(sprintf(
                            '<error>SKIP %s: configuration %s/%s does not own exactly this one workspace (has: %s).</error>',
                            $description,
                            $component,
                            $configurationId,
                            implode(', ', $configWorkspaceIds),
                        ));
                        $skippedConfigGuard++;
                        continue;
                    }

                    if (!$force) {
                        $output->writeln(sprintf('[DRY-RUN] Would %s including %s.', $actionDescription, $description));
                        $deleted++;
                        continue;
                    }

                    try {
                        // deleting a live configuration moves it to trash, deleting a trashed
                        // one purges it permanently (which drops the workspace and its backend user)
                        for ($i = 0; $i < $deleteCallCount; $i++) {
                            try {
                                $components->deleteConfiguration($component, $configurationId);
                            } catch (StorageClientException $e) {
                                if ($e->getStringCode() !== 'storage.components.cannotDeleteConfiguration') {
                                    throw $e;
                                }
                            }
                        }
                        $output->writeln(sprintf('Done: %s including %s.', $actionDescription, $description));
                        $deleted++;
                    } catch (\Throwable $e) {
                        $output->writeln(sprintf('<error>Error: %s (%s): %s</error>', $actionDescription, $description, $e->getMessage()));
                        $failed++;
                    }
                    continue;
                }

                if (!$force) {
                    $output->writeln(sprintf('[DRY-RUN] Would delete %s.', $description));
                    $deleted++;
                    continue;
                }

                try {
                    $workspacesClient = new Workspaces($branchClient);
                    $workspacesClient->deleteWorkspace((int) $workspaceId);
                    $output->writeln(sprintf('Deleted %s.', $description));
                    $deleted++;
                } catch (\Throwable $e) {
                    $output->writeln(sprintf('<error>Error deleting %s: %s</error>', $description, $e->getMessage()));
                    $failed++;
                }
            }

            $tokensClient = new Tokens($storageClient);
            assert(is_scalar($storageToken['id']));
            $tokensClient->dropToken((int) $storageToken['id']);
        }

        $output->writeln(sprintf(
            '%s %d of %d workspaces (%d skipped on login type, %d skipped on schema mismatch, %d skipped on configuration guard, %d failed, %d not found).',
            $force ? 'Deleted' : '[DRY-RUN] Would delete',
            $deleted,
            $totalRows,
            $skippedLoginType,
            $skippedSchemaMismatch,
            $skippedConfigGuard,
            $failed,
            count($notFound),
        ));
        if (count($notFound) !== 0) {
            $output->writeln([
                '<error>Following workspaces were not found (already deleted or wrong project?):</error>',
                implode(', ', $notFound),
            ]);
        }

        return 0;
    }
}
