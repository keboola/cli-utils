<?php

namespace Keboola\Console\Command;

use InvalidArgumentException;
use Keboola\Csv\CsvFile;
use Keboola\ManageApi\Client;
use Keboola\ManageApi\ClientException;
use Keboola\ServiceClient\ServiceClient;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client as StorageApiClient;
use Keboola\StorageApi\ClientException as StorageClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\DevBranches;
use Keboola\StorageApi\Tokens;
use Keboola\StorageApi\WorkspaceLoginType;
use Keboola\StorageApi\Workspaces;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteOrganizationOrphanedWorkspaces extends Command
{
    private const COMPONENT_GROUPS = [
        'transformations' => [
            'keboola.snowflake-transformation',
            'keboola.legacy-transformation',
            'transformation',
        ],
    ];

    protected function configure(): void
    {
        $this
            ->setName('manage:delete-organization-workspaces')
            ->setDescription('Bulk delete workspaces of this organization (orphaned by component/age, or by keep-rule).')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Use [--force, -f] to do it for real.')
            ->addArgument(
                'manageToken',
                InputArgument::REQUIRED,
                'Keboola Storage API token to use'
            )
            ->addArgument(
                'organizationId',
                InputArgument::REQUIRED,
                'Comma-separated list of organization IDs to clean'
            )
            ->addOption(
                'component',
                'c',
                InputOption::VALUE_REQUIRED,
                'Component that qualifies for orphanage (ex. keboola.snowflake-transformation, or "" for empty/blank components).'
            )
            ->addOption(
                'component-group',
                'g',
                InputOption::VALUE_REQUIRED,
                sprintf(
                    'Use a predefined component group instead of a single component. Available groups: %s',
                    implode(', ', array_keys(self::COMPONENT_GROUPS))
                )
            )
            ->addOption(
                'hostname-suffix',
                'H',
                InputOption::VALUE_REQUIRED,
                'Keboola Connection Hostname Suffix',
                'keboola.com'
            )
            ->addOption(
                'until-date',
                'd',
                InputOption::VALUE_REQUIRED,
                'String representation of cutoff date',
                '-1 month'
            )
            ->addOption(
                'keep-creator',
                null,
                InputOption::VALUE_REQUIRED,
                'Keep-rule mode: keep workspaces whose creator email matches this value (delete all others). '
                . 'Setting this (or --keep-created-from) switches the command from orphan mode to keep-rule mode '
                . 'and makes --component/--component-group optional.'
            )
            ->addOption(
                'keep-created-from',
                null,
                InputOption::VALUE_REQUIRED,
                'Keep-rule mode: keep workspaces created on/after this date (e.g. "2026-01-01"). '
                . 'Combined with --keep-creator a workspace is kept only when BOTH conditions match.'
            )
            ->addOption(
                'legacy-only',
                null,
                InputOption::VALUE_NONE,
                'Only consider legacy_service workspaces (loginType "default" or "snowflake-legacy-service", i.e. internal login_type 0).'
            )
            ->addOption(
                'output-file',
                'o',
                InputOption::VALUE_REQUIRED,
                'Write a describe-style CSV of evaluated workspaces (same columns as manage:describe-organization-workspaces plus an "action" column).'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manageToken = $input->getArgument('manageToken');
        assert(is_string($manageToken));
        $hostnameSuffix = $input->getOption('hostname-suffix');
        assert(is_string($hostnameSuffix));
        assert($hostnameSuffix !== '');
        $serviceClient = new ServiceClient($hostnameSuffix);
        $connectionUrl = $serviceClient->getConnectionServiceUrl();

        // Parse organization IDs
        $organizationIdArg = $input->getArgument('organizationId');
        assert(is_string($organizationIdArg));
        $organizationIds = array_map('intval', array_filter(explode(',', $organizationIdArg), 'is_numeric'));
        if ($organizationIds === []) {
            throw new InvalidArgumentException('No valid organization IDs provided.');
        }

        // Keep-rule options
        $keepCreator = $input->getOption('keep-creator');
        $keepCreatedFromStr = $input->getOption('keep-created-from');
        assert($keepCreator === null || is_string($keepCreator));
        assert($keepCreatedFromStr === null || is_string($keepCreatedFromStr));
        $legacyOnly = (bool) $input->getOption('legacy-only');
        $keepRuleMode = $keepCreator !== null || $keepCreatedFromStr !== null;

        $keepCreatedFrom = null;
        if ($keepCreatedFromStr !== null) {
            $keepCreatedFrom = strtotime($keepCreatedFromStr);
            if ($keepCreatedFrom === false) {
                throw new InvalidArgumentException(sprintf('Invalid --keep-created-from date: %s', $keepCreatedFromStr));
            }
        }

        // Resolve target components
        $orphanComponent = $input->getOption('component');
        $componentGroup = $input->getOption('component-group');
        assert($orphanComponent === null || is_string($orphanComponent));
        assert($componentGroup === null || is_string($componentGroup));

        if ($orphanComponent !== null && $componentGroup !== null) {
            throw new InvalidArgumentException('Cannot use both --component and --component-group options.');
        }
        if (!$keepRuleMode && $orphanComponent === null && $componentGroup === null) {
            throw new InvalidArgumentException(
                'Either --component or --component-group option must be provided (orphan mode), '
                . 'or use --keep-creator/--keep-created-from (keep-rule mode).'
            );
        }

        $hasComponentFilter = $orphanComponent !== null || $componentGroup !== null;
        /** @var list<string> $targetComponents */
        $targetComponents = [];
        $componentDesc = $keepRuleMode ? '(all components)' : '';
        if ($componentGroup !== null) {
            if (!isset(self::COMPONENT_GROUPS[$componentGroup])) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown component group "%s". Available groups: %s',
                    $componentGroup,
                    implode(', ', array_keys(self::COMPONENT_GROUPS))
                ));
            }
            $targetComponents = self::COMPONENT_GROUPS[$componentGroup];
            $componentDesc = sprintf('group "%s" (%s)', $componentGroup, implode(', ', $targetComponents));
        } elseif ($orphanComponent !== null) {
            $targetComponents = [$orphanComponent];
            $componentDesc = $orphanComponent === '' ? '(empty/blank)' : $orphanComponent;
        }

        $untilDateStr = $input->getOption('until-date');
        assert(is_string($untilDateStr));
        $untilDate = strtotime($untilDateStr);
        if ($untilDate === false) {
            throw new InvalidArgumentException(sprintf('Invalid date format: %s', $untilDateStr));
        }

        $force = (bool) $input->getOption('force');

        $outputFile = $input->getOption('output-file');
        assert($outputFile === null || is_string($outputFile));
        $csvFile = null;
        if ($outputFile !== null) {
            $csvFile = new CsvFile($outputFile);
            $csvFile->writeRow([
                'projectId',
                'projectName',
                'branchId',
                'branchName',
                'componentId',
                'configurationId',
                'creatorEmail',
                'activeUser',
                'createdDate',
                'snowflakeSchema',
                'readOnlyStorageAccess',
                'loginType',
                'configStatus',
                'action',
            ]);
        }

        // ====================================================================
        // Configuration summary
        // ====================================================================
        $configLines = [
            sprintf('Organization IDs:   %s', implode(', ', $organizationIds)),
            sprintf('Mode:               %s', $keepRuleMode ? 'KEEP-RULE' : 'ORPHAN'),
            sprintf('Target component:   %s', $componentDesc),
        ];
        if ($keepRuleMode) {
            $configLines[] = sprintf('Keep creator:       %s', $keepCreator ?? '(any)');
            $configLines[] = sprintf(
                'Keep created from:  %s',
                $keepCreatedFrom !== null ? date('Y-m-d H:i:s', $keepCreatedFrom) : '(any)'
            );
        } else {
            $configLines[] = sprintf('Until date:         %s (%s)', $untilDateStr, date('Y-m-d H:i:s', $untilDate));
        }
        $configLines[] = sprintf('Legacy-only:        %s', $legacyOnly ? 'yes' : 'no');
        $configLines[] = sprintf('Output file:        %s', $outputFile ?? '(none)');
        $configLines[] = sprintf('Run:                %s', $force ? 'FORCE (workspaces will be deleted)' : 'DRY-RUN (nothing will be deleted)');
        $this->writeBlock($output, 'Configuration', $configLines);

        $manageClient = new Client(['token' => $manageToken, 'url' => $connectionUrl]);

        $totalOrgsProcessed = 0;
        $totalOrgsFailed = 0;
        $totalProjectsProcessed = 0;
        $totalProjectsSkipped = 0;
        $totalWorkspaces = 0;
        $totalDeletedWorkspaces = 0;
        $totalSkippedWorkspaces = 0;
        $totalDeleteErrors = 0;
        $totalConfigsDeleted = 0;
        /** @var array<string, int> $skippedComponentCounts */
        $skippedComponentCounts = [];
        /** @var array<string, int> $deletedComponentCounts */
        $deletedComponentCounts = [];
        /** @var array<string, int> $deleteConfigStatusCounts */
        $deleteConfigStatusCounts = ['exists' => 0, 'missing' => 0, 'none' => 0, 'unknown' => 0];

        foreach ($organizationIds as $organizationId) {
            try {
                $organization = $manageClient->getOrganization($organizationId);
            } catch (ClientException $e) {
                $output->writeln(sprintf('ERROR: Failed to load organization %d: %s', $organizationId, $e->getMessage()));
                $output->writeln('');
                $totalOrgsFailed++;
                continue;
            }
            $projects = $organization['projects'];

            $this->writeBlock($output, sprintf('Organization %d (%d projects)', $organizationId, count($projects)));

            $orgProjectsProcessed = 0;
            $orgProjectsSkipped = 0;
            $orgWorkspaces = 0;
            $orgDeletedWorkspaces = 0;
            $orgSkippedWorkspaces = 0;
            $orgDeleteErrors = 0;
            $orgConfigsDeleted = 0;

            foreach ($projects as $project) {
                $this->writeBlock($output, sprintf('Project %s : %s', $project['id'], $project['name']));

                $projectUsers = $manageClient->listProjectUsers($project['id']);

                try {
                    $storageToken = $manageClient->createProjectStorageToken(
                        $project['id'],
                        [
                            'description' => 'Maintenance Workspace Cleaner',
                            'expiresIn' => 1800,
                            // Full storage access is required to read/delete component configurations
                            // (workspace listing alone works without it, but getConfiguration returns 403).
                            'canManageBuckets' => true,
                            'canManageTokens' => false,
                            'canReadAllFileUploads' => true,
                            // Allows the second deleteConfiguration call to permanently purge the configuration.
                            'canPurgeTrash' => true,
                        ]
                    );
                } catch (\Throwable $e) {
                    if ($e->getCode() === 403) {
                        $output->writeln(sprintf('  WARN: Access denied to project %s, skipping.', $project['id']));
                        $output->writeln('');
                        $orgProjectsSkipped++;
                        continue;
                    }
                    throw $e;
                }

                $storageClient = new StorageApiClient([
                    'token' => $storageToken['token'],
                    'url' => $connectionUrl,
                    'logger' => new ConsoleLogger($output),
                ]);
                $devBranches = new DevBranches($storageClient);
                $branchesList = $devBranches->listBranches();

                $totalProjectWorkspaces = 0;
                $totalProjectDeletedWorkspaces = 0;
                $totalProjectSkippedWorkspaces = 0;
                $totalProjectDeleteErrors = 0;
                $totalProjectConfigsDeleted = 0;

                foreach ($branchesList as $branch) {
                    $branchId = $branch['id'];
                    $branchStorageClient = new BranchAwareClient($branchId, [
                        'token' => $storageToken['token'],
                        'url' => $connectionUrl,
                        'backoffMaxTries' => 1,
                    ]);
                    $workspacesClient = new Workspaces($branchStorageClient);
                    $components = new Components($branchStorageClient);
                    $workspaceList = $workspacesClient->listWorkspaces();

                    $output->writeln(sprintf('  Branch "%s" (#%s): %d workspace(s)', $branch['name'], $branchId, count($workspaceList)));
                    $totalProjectWorkspaces += count($workspaceList);

                    foreach ($workspaceList as $workspace) {
                        $componentKey = !empty($workspace['component']) ? $workspace['component'] : '<none>';
                        $shouldDropWorkspace = $this->shouldDeleteWorkspace(
                            $workspace,
                            $keepRuleMode,
                            $keepCreator,
                            $keepCreatedFrom,
                            $legacyOnly,
                            $targetComponents,
                            $hasComponentFilter,
                            $untilDate
                        );
                        // Resolve configuration existence only for workspaces we intend to delete
                        $configStatus = $shouldDropWorkspace
                            ? $this->resolveConfigStatus($workspace, $components)
                            : '';
                        if ($csvFile !== null) {
                            $this->writeCsvRow(
                                $csvFile,
                                $project,
                                $branch,
                                $workspace,
                                $projectUsers,
                                $shouldDropWorkspace ? 'DELETE' : 'KEEP',
                                $configStatus
                            );
                        }
                        if ($shouldDropWorkspace) {
                            $output->writeln(sprintf(
                                '    - DELETE workspace %s (component: %s, created: %s, config: %s)',
                                (string) $workspace['id'],
                                $componentKey,
                                $workspace['created'],
                                $configStatus
                            ));
                            $totalProjectDeletedWorkspaces++;
                            $deletedComponentCounts[$componentKey] = ($deletedComponentCounts[$componentKey] ?? 0) + 1;
                            $deleteConfigStatusCounts[$configStatus] = ($deleteConfigStatusCounts[$configStatus] ?? 0) + 1;
                            if ($force) {
                                try {
                                    $workspacesClient->deleteWorkspace($workspace['id']);
                                } catch (StorageClientException $clientException) {
                                    // 404 means the workspace was already gone (e.g. removed by config cascade)
                                    if ($clientException->getCode() !== 404) {
                                        $output->writeln(sprintf(
                                            '      ERROR deleting workspace %s: %s',
                                            (string) $workspace['id'],
                                            $clientException->getMessage()
                                        ));
                                        $totalProjectDeleteErrors++;
                                    }
                                }

                                if ($configStatus === 'exists') {
                                    $configDeleted = $this->deleteConfiguration($output, $components, $workspace);
                                    if ($configDeleted) {
                                        $totalProjectConfigsDeleted++;
                                    } else {
                                        $totalProjectDeleteErrors++;
                                    }
                                }
                            }
                        } else {
                            $output->writeln(sprintf(
                                '    - SKIP   workspace %s (component: %s, created: %s)',
                                (string) $workspace['id'],
                                $componentKey,
                                $workspace['created']
                            ));
                            $totalProjectSkippedWorkspaces++;
                            $skippedComponentCounts[$componentKey] = ($skippedComponentCounts[$componentKey] ?? 0) + 1;
                        }
                    }
                }

                $output->writeln('');
                $output->writeln(sprintf(
                    '  Project summary: %d workspace(s) total, %d to delete, %d skipped, %d config(s) deleted%s',
                    $totalProjectWorkspaces,
                    $totalProjectDeletedWorkspaces,
                    $totalProjectSkippedWorkspaces,
                    $totalProjectConfigsDeleted,
                    $totalProjectDeleteErrors > 0 ? sprintf(', %d delete error(s)', $totalProjectDeleteErrors) : ''
                ));
                $output->writeln('');
                $output->writeln('Dropping token ' . $storageToken['id']);

                $tokensClient = new Tokens($storageClient);
                $tokensClient->dropToken($storageToken['id']);
                $output->writeln('Dropped token ' . $storageToken['id']);

                $orgProjectsProcessed++;
                $orgWorkspaces += $totalProjectWorkspaces;
                $orgDeletedWorkspaces += $totalProjectDeletedWorkspaces;
                $orgSkippedWorkspaces += $totalProjectSkippedWorkspaces;
                $orgDeleteErrors += $totalProjectDeleteErrors;
                $orgConfigsDeleted += $totalProjectConfigsDeleted;
            }

            $this->writeBlock($output, sprintf('Organization %d summary', $organizationId), [
                sprintf('Projects processed:    %d', $orgProjectsProcessed),
                sprintf('Projects skipped:      %d', $orgProjectsSkipped),
                sprintf('Workspaces found:      %d', $orgWorkspaces),
                sprintf('Workspaces to delete:  %d', $orgDeletedWorkspaces),
                sprintf('Workspaces skipped:    %d', $orgSkippedWorkspaces),
                sprintf('Configs deleted:       %d', $orgConfigsDeleted),
            ]);

            $totalOrgsProcessed++;
            $totalProjectsProcessed += $orgProjectsProcessed;
            $totalProjectsSkipped += $orgProjectsSkipped;
            $totalWorkspaces += $orgWorkspaces;
            $totalDeletedWorkspaces += $orgDeletedWorkspaces;
            $totalSkippedWorkspaces += $orgSkippedWorkspaces;
            $totalDeleteErrors += $orgDeleteErrors;
            $totalConfigsDeleted += $orgConfigsDeleted;
        }

        // ====================================================================
        // Final summary
        // ====================================================================
        $summaryLines = [
            sprintf('Organizations processed: %d', $totalOrgsProcessed),
            sprintf('Organizations failed:    %d', $totalOrgsFailed),
            sprintf('Projects processed:      %d', $totalProjectsProcessed),
            sprintf('Projects skipped:        %d', $totalProjectsSkipped),
            sprintf('Workspaces found:        %d', $totalWorkspaces),
            sprintf('Workspaces to delete:    %d', $totalDeletedWorkspaces),
            sprintf('Workspaces skipped:      %d', $totalSkippedWorkspaces),
            sprintf(
                'Config existence (to-delete): exists %d, missing %d, none %d, unknown %d',
                $deleteConfigStatusCounts['exists'] ?? 0,
                $deleteConfigStatusCounts['missing'] ?? 0,
                $deleteConfigStatusCounts['none'] ?? 0,
                $deleteConfigStatusCounts['unknown'] ?? 0
            ),
        ];
        if ($force) {
            $summaryLines[] = sprintf('Configs deleted:         %d', $totalConfigsDeleted);
            $summaryLines[] = sprintf('Delete errors:           %d', $totalDeleteErrors);
        } else {
            $summaryLines[] = 'Mode:                    DRY-RUN (re-run with --force to delete)';
        }

        if ($deletedComponentCounts !== []) {
            arsort($deletedComponentCounts);
            $summaryLines[] = '';
            $summaryLines[] = sprintf(
                '%s workspaces by component:',
                $force ? 'Deleted' : 'To-delete'
            );
            $maxKeyLen = max(array_map('strlen', array_keys($deletedComponentCounts)));
            foreach ($deletedComponentCounts as $component => $count) {
                $summaryLines[] = sprintf('  %-' . $maxKeyLen . 's  %d', $component, $count);
            }
        }

        if ($skippedComponentCounts !== []) {
            arsort($skippedComponentCounts);
            $summaryLines[] = '';
            $summaryLines[] = 'Skipped workspaces by component:';
            $maxKeyLen = max(array_map('strlen', array_keys($skippedComponentCounts)));
            foreach ($skippedComponentCounts as $component => $count) {
                $summaryLines[] = sprintf('  %-' . $maxKeyLen . 's  %d', $component, $count);
            }
        }

        $this->writeBlock($output, 'Final summary', $summaryLines);

        return 0;
    }

    /**
     * @param list<string> $lines
     */
    private function writeBlock(OutputInterface $output, string $title, array $lines = []): void
    {
        $width = max(70, strlen($title) + 4);
        $separator = str_repeat('=', $width);
        $output->writeln($separator);
        $output->writeln('  ' . $title);
        $output->writeln($separator);
        foreach ($lines as $line) {
            $output->writeln('  ' . $line);
        }
        if ($lines !== []) {
            $output->writeln('');
        }
    }

    /**
     * Decide whether a workspace should be deleted.
     *
     * Scope filters (component, legacy-only) exclude a workspace from deletion when not matched.
     * In keep-rule mode a workspace is deleted unless it matches ALL provided keep conditions.
     * In orphan mode a workspace is deleted when its component matches and it was created before the cutoff.
     *
     * @param array<string, mixed> $workspace
     * @param list<string> $targetComponents
     */
    private function shouldDeleteWorkspace(
        array $workspace,
        bool $keepRuleMode,
        ?string $keepCreator,
        ?int $keepCreatedFrom,
        bool $legacyOnly,
        array $targetComponents,
        bool $hasComponentFilter,
        int $untilDate
    ): bool {
        // Scope: legacy_service (password login) workspaces only
        if ($legacyOnly) {
            $loginType = $this->extractLoginType($workspace);
            if (WorkspaceLoginType::tryFrom($loginType)?->isPasswordLogin() !== true) {
                return false;
            }
        }

        // Scope: component filter (required in orphan mode, optional in keep-rule mode)
        if ($hasComponentFilter) {
            $workspaceComponent = $workspace['component'] ?? '';
            assert(is_string($workspaceComponent));
            if (in_array('', $targetComponents, true)) {
                if ($workspaceComponent !== '') {
                    return false;
                }
            } elseif (!in_array($workspaceComponent, $targetComponents, true)) {
                return false;
            }
        }

        $createdDate = $workspace['created'];
        assert(is_string($createdDate));

        if ($keepRuleMode) {
            $kept = true;
            // Match the creator as a substring (case-insensitive): the creatorToken description may wrap
            // the email, e.g. "kbagent-cli [david.kohout@carvago.com]" for workspaces created on his behalf.
            if ($keepCreator !== null && stripos($this->extractCreatorEmail($workspace), $keepCreator) === false) {
                $kept = false;
            }
            if ($keepCreatedFrom !== null && strtotime($createdDate) < $keepCreatedFrom) {
                $kept = false;
            }
            return !$kept;
        }

        // Orphan mode: delete workspaces created before the cutoff date
        return strtotime($createdDate) < $untilDate;
    }

    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed> $branch
     * @param array<string, mixed> $workspace
     * @param list<array<string, mixed>> $projectUsers
     */
    private function writeCsvRow(
        CsvFile $csvFile,
        array $project,
        array $branch,
        array $workspace,
        array $projectUsers,
        string $action,
        string $configStatus
    ): void {
        $creatorEmail = $this->extractCreatorEmail($workspace);
        $userInProject = count(array_filter($projectUsers, function ($user) use ($creatorEmail) {
            return $user['email'] === $creatorEmail;
        }));
        $csvFile->writeRow([
            $project['id'],
            $project['name'],
            $branch['id'],
            $branch['name'],
            $workspace['component'],
            $workspace['configurationId'],
            $creatorEmail,
            $userInProject > 0 ? 'true' : 'false',
            $workspace['created'],
            $workspace['name'],
            $workspace['readOnlyStorageAccess'],
            $this->extractLoginType($workspace),
            $configStatus,
            $action,
        ]);
    }

    /**
     * Determine whether the workspace's backing configuration still exists.
     * Returns "none" when the workspace has no component/configuration reference,
     * "exists" when the configuration is found, "missing" when it was not found (orphaned).
     *
     * @param array<string, mixed> $workspace
     */
    private function resolveConfigStatus(array $workspace, Components $components): string
    {
        $component = $this->extractComponentId($workspace);
        $configurationId = $this->extractConfigurationId($workspace);
        if ($component === '' || $configurationId === null) {
            return 'none';
        }

        try {
            $components->getConfiguration($component, $configurationId);
            return 'exists';
        } catch (StorageClientException $e) {
            if ($e->getCode() === 404) {
                return 'missing';
            }
            // e.g. 403 accessDenied - we cannot determine existence; do not block workspace deletion
            return 'unknown';
        }
    }

    /**
     * Delete the workspace's configuration (move to trash, then purge). Returns true on success.
     *
     * @param array<string, mixed> $workspace
     */
    private function deleteConfiguration(OutputInterface $output, Components $components, array $workspace): bool
    {
        $component = $this->extractComponentId($workspace);
        $configurationId = $this->extractConfigurationId($workspace);
        if ($component === '' || $configurationId === null) {
            return false;
        }

        try {
            // First call moves the configuration to trash, second call permanently purges it.
            $components->deleteConfiguration($component, $configurationId);
            $components->deleteConfiguration($component, $configurationId);
            return true;
        } catch (StorageClientException $e) {
            if ($e->getStringCode() === 'storage.components.cannotDeleteConfiguration') {
                return true;
            }
            $output->writeln(sprintf(
                '      ERROR deleting configuration %s/%s: %s',
                $component,
                $configurationId,
                $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * @param array<string, mixed> $workspace
     */
    private function extractLoginType(array $workspace): string
    {
        $connection = $workspace['connection'] ?? [];
        if (!is_array($connection)) {
            return '';
        }
        $loginType = $connection['loginType'] ?? '';
        return is_string($loginType) ? $loginType : '';
    }

    /**
     * @param array<string, mixed> $workspace
     */
    private function extractCreatorEmail(array $workspace): string
    {
        $creatorToken = $workspace['creatorToken'] ?? [];
        if (!is_array($creatorToken)) {
            return '';
        }
        $description = $creatorToken['description'] ?? '';
        return is_string($description) ? $description : '';
    }

    /**
     * @param array<string, mixed> $workspace
     */
    private function extractComponentId(array $workspace): string
    {
        $component = $workspace['component'] ?? '';
        return is_string($component) ? $component : '';
    }

    /**
     * @param array<string, mixed> $workspace
     */
    private function extractConfigurationId(array $workspace): ?string
    {
        $configurationId = $workspace['configurationId'] ?? null;
        if (is_int($configurationId)) {
            return (string) $configurationId;
        }
        if (is_string($configurationId) && $configurationId !== '') {
            return $configurationId;
        }
        return null;
    }
}
