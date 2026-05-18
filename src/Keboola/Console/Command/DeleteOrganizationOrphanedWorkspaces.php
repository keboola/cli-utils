<?php

namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Keboola\ManageApi\ClientException;
use Keboola\ServiceClient\ServiceClient;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client as StorageApiClient;
use Keboola\StorageApi\DevBranches;
use Keboola\StorageApi\Tokens;
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
            ->setDescription('Bulk delete orphaned workspaces of this organization.')
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
            throw new \InvalidArgumentException('No valid organization IDs provided.');
        }

        // Resolve target components
        $orphanComponent = $input->getOption('component');
        $componentGroup = $input->getOption('component-group');
        assert($orphanComponent === null || is_string($orphanComponent));
        assert($componentGroup === null || is_string($componentGroup));

        if ($orphanComponent !== null && $componentGroup !== null) {
            throw new \InvalidArgumentException('Cannot use both --component and --component-group options.');
        }
        if ($orphanComponent === null && $componentGroup === null) {
            throw new \InvalidArgumentException('Either --component or --component-group option must be provided.');
        }

        /** @var list<string> $targetComponents */
        $targetComponents = [];
        $componentDesc = '';
        if ($componentGroup !== null) {
            if (!isset(self::COMPONENT_GROUPS[$componentGroup])) {
                throw new \InvalidArgumentException(sprintf(
                    'Unknown component group "%s". Available groups: %s',
                    $componentGroup,
                    implode(', ', array_keys(self::COMPONENT_GROUPS))
                ));
            }
            $targetComponents = self::COMPONENT_GROUPS[$componentGroup];
            $componentDesc = sprintf('group "%s" (%s)', $componentGroup, implode(', ', $targetComponents));
        } else {
            $targetComponents = [$orphanComponent];
            $componentDesc = empty($orphanComponent) ? '(empty/blank)' : $orphanComponent;
        }

        $untilDateStr = $input->getOption('until-date');
        assert(is_string($untilDateStr));
        $untilDate = strtotime($untilDateStr);
        if ($untilDate === false) {
            throw new \InvalidArgumentException(sprintf('Invalid date format: %s', $untilDateStr));
        }

        $force = (bool) $input->getOption('force');

        // ====================================================================
        // Configuration summary
        // ====================================================================
        $this->writeBlock($output, 'Configuration', [
            sprintf('Organization IDs:   %s', implode(', ', $organizationIds)),
            sprintf('Target component:   %s', $componentDesc),
            sprintf('Until date:         %s (%s)', $untilDateStr, date('Y-m-d H:i:s', $untilDate)),
            sprintf('Mode:               %s', $force ? 'FORCE (workspaces will be deleted)' : 'DRY-RUN (nothing will be deleted)'),
        ]);

        $manageClient = new Client(['token' => $manageToken, 'url' => $connectionUrl]);

        $totalOrgsProcessed = 0;
        $totalOrgsFailed = 0;
        $totalProjectsProcessed = 0;
        $totalProjectsSkipped = 0;
        $totalWorkspaces = 0;
        $totalDeletedWorkspaces = 0;
        $totalSkippedWorkspaces = 0;
        $totalDeleteErrors = 0;
        /** @var array<string, int> $skippedComponentCounts */
        $skippedComponentCounts = [];
        /** @var array<string, int> $deletedComponentCounts */
        $deletedComponentCounts = [];

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

            foreach ($projects as $project) {
                $this->writeBlock($output, sprintf('Project %s : %s', $project['id'], $project['name']));

                try {
                    $storageToken = $manageClient->createProjectStorageToken(
                        $project['id'],
                        [
                            'description' => 'Maintenance Workspace Cleaner',
                            'expiresIn' => 1800,
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

                foreach ($branchesList as $branch) {
                    $branchId = $branch['id'];
                    $branchStorageClient = new BranchAwareClient($branchId, [
                        'token' => $storageToken['token'],
                        'url' => $connectionUrl,
                        'backoffMaxTries' => 1,
                    ]);
                    $workspacesClient = new Workspaces($branchStorageClient);
                    $workspaceList = $workspacesClient->listWorkspaces();

                    $output->writeln(sprintf('  Branch "%s" (#%s): %d workspace(s)', $branch['name'], $branchId, count($workspaceList)));
                    $totalProjectWorkspaces += count($workspaceList);

                    foreach ($workspaceList as $workspace) {
                        $componentKey = !empty($workspace['component']) ? $workspace['component'] : '<none>';
                        $shouldDropWorkspace = $this->isWorkspaceOrphaned(
                            $workspace,
                            $targetComponents,
                            $untilDate
                        );
                        if ($shouldDropWorkspace) {
                            $output->writeln(sprintf(
                                '    - DELETE workspace %s (component: %s, created: %s)',
                                (string) $workspace['id'],
                                $componentKey,
                                $workspace['created']
                            ));
                            $totalProjectDeletedWorkspaces++;
                            $deletedComponentCounts[$componentKey] = ($deletedComponentCounts[$componentKey] ?? 0) + 1;
                            if ($force) {
                                try {
                                    $workspacesClient->deleteWorkspace($workspace['id']);
                                } catch (\Throwable $clientException) {
                                    $output->writeln(sprintf(
                                        '      ERROR deleting workspace %s: %s',
                                        (string) $workspace['id'],
                                        $clientException->getMessage()
                                    ));
                                    $totalProjectDeleteErrors++;
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
                    '  Project summary: %d workspace(s) total, %d to delete, %d skipped%s',
                    $totalProjectWorkspaces,
                    $totalProjectDeletedWorkspaces,
                    $totalProjectSkippedWorkspaces,
                    $totalProjectDeleteErrors > 0 ? sprintf(', %d delete error(s)', $totalProjectDeleteErrors) : ''
                ));
                $output->writeln('');

                $tokensClient = new Tokens($storageClient);
                $tokensClient->dropToken($storageToken['id']);

                $orgProjectsProcessed++;
                $orgWorkspaces += $totalProjectWorkspaces;
                $orgDeletedWorkspaces += $totalProjectDeletedWorkspaces;
                $orgSkippedWorkspaces += $totalProjectSkippedWorkspaces;
                $orgDeleteErrors += $totalProjectDeleteErrors;
            }

            $this->writeBlock($output, sprintf('Organization %d summary', $organizationId), [
                sprintf('Projects processed:    %d', $orgProjectsProcessed),
                sprintf('Projects skipped:      %d', $orgProjectsSkipped),
                sprintf('Workspaces found:      %d', $orgWorkspaces),
                sprintf('Workspaces to delete:  %d', $orgDeletedWorkspaces),
                sprintf('Workspaces skipped:    %d', $orgSkippedWorkspaces),
            ]);

            $totalOrgsProcessed++;
            $totalProjectsProcessed += $orgProjectsProcessed;
            $totalProjectsSkipped += $orgProjectsSkipped;
            $totalWorkspaces += $orgWorkspaces;
            $totalDeletedWorkspaces += $orgDeletedWorkspaces;
            $totalSkippedWorkspaces += $orgSkippedWorkspaces;
            $totalDeleteErrors += $orgDeleteErrors;
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
        ];
        if ($force) {
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
     * @param array<string, mixed> $workspace
     * @param list<string> $components
     */
    private function isWorkspaceOrphaned(array $workspace, array $components, int $untilDate): bool
    {
        $workspaceComponent = $workspace['component'] ?? '';
        assert(is_string($workspaceComponent));

        // Check if empty-string component is in the target list (matching blank/empty workspaces)
        if (in_array('', $components, true)) {
            if ($workspaceComponent !== '') {
                return false;
            }
        } else {
            if (!in_array($workspaceComponent, $components, true)) {
                return false;
            }
        }

        // Skip workspaces created after or on the cutoff date
        $createdDate = $workspace['created'];
        assert(is_string($createdDate));
        if (strtotime($createdDate) >= $untilDate) {
            return false;
        }
        // If all conditions pass, the workspace qualifies
        return true;
    }
}
