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
                'ID of the organization to clean'
            )
            ->addArgument(
                'orphanComponent',
                InputArgument::REQUIRED,
                'Component that qualify for orphanage (ex. keboola.snowflake-transformation, or "" for empty/blank components).'
            )
            ->addArgument(
                'hostnameSuffix',
                InputArgument::OPTIONAL,
                'Keboola Connection Hostname Suffix',
                'keboola.com'
            )
            ->addArgument(
                'untilDate',
                InputArgument::OPTIONAL,
                'String representation of date: default: \'-1 month\'',
                '-1 month'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manageToken = $input->getArgument('manageToken');
        assert(is_string($manageToken));
        $organizationId = $input->getArgument('organizationId');
        assert(is_string($organizationId));
        $organizationId = is_numeric($organizationId) ? (int) $organizationId : (int) $organizationId;
        $organizationId = (int) $organizationId;
        $hostnameSuffix = $input->getArgument('hostnameSuffix');
        assert(is_string($hostnameSuffix));
        assert($hostnameSuffix !== '');
        $serviceClient = new ServiceClient($hostnameSuffix);
        $connectionUrl = $serviceClient->getConnectionServiceUrl();

        $manageClient = new Client(['token' => $manageToken, 'url' => $connectionUrl]);
        $organization = $manageClient->getOrganization($organizationId);
        $projects = $organization['projects'];

        $orphanComponent = $input->getArgument('orphanComponent');
        assert(is_string($orphanComponent));
        $componentDesc = empty($orphanComponent) ? '(empty/blank)' : $orphanComponent;

        $untilDateStr = $input->getArgument('untilDate');
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
            sprintf('Organization ID:    %d', $organizationId),
            sprintf('Projects to check:  %d', count($projects)),
            sprintf('Target component:   %s', $componentDesc),
            sprintf('Until date:         %s (%s)', $untilDateStr, date('Y-m-d H:i:s', $untilDate)),
            sprintf('Mode:               %s', $force ? 'FORCE (workspaces will be deleted)' : 'DRY-RUN (nothing will be deleted)'),
        ]);

        $totalProjectsProcessed = 0;
        $totalProjectsSkipped = 0;
        $totalWorkspaces = 0;
        $totalDeletedWorkspaces = 0;
        $totalSkippedWorkspaces = 0;
        $totalDeleteErrors = 0;
        /** @var array<string, int> $skippedComponentCounts */
        $skippedComponentCounts = [];

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
                    $totalProjectsSkipped++;
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
                    $shouldDropWorkspace = $this->isWorkspaceOrphaned(
                        $workspace,
                        $orphanComponent,
                        $untilDate
                    );
                    if ($shouldDropWorkspace) {
                        $output->writeln(sprintf(
                            '    - DELETE workspace %s (component: %s, created: %s)',
                            (string) $workspace['id'],
                            !empty($workspace['component']) ? $workspace['component'] : '<none>',
                            $workspace['created']
                        ));
                        $totalProjectDeletedWorkspaces++;
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
                        $componentKey = !empty($workspace['component']) ? $workspace['component'] : '<none>';
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

            $totalProjectsProcessed++;
            $totalWorkspaces += $totalProjectWorkspaces;
            $totalDeletedWorkspaces += $totalProjectDeletedWorkspaces;
            $totalSkippedWorkspaces += $totalProjectSkippedWorkspaces;
            $totalDeleteErrors += $totalProjectDeleteErrors;
        }

        // ====================================================================
        // Final summary
        // ====================================================================
        $summaryLines = [
            sprintf('Projects processed:    %d', $totalProjectsProcessed),
            sprintf('Projects skipped:      %d', $totalProjectsSkipped),
            sprintf('Workspaces found:      %d', $totalWorkspaces),
            sprintf('Workspaces to delete:  %d', $totalDeletedWorkspaces),
            sprintf('Workspaces skipped:    %d', $totalSkippedWorkspaces),
        ];
        if ($force) {
            $summaryLines[] = sprintf('Delete errors:         %d', $totalDeleteErrors);
        } else {
            $summaryLines[] = 'Mode:                  DRY-RUN (re-run with --force to delete)';
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
     */
    private function isWorkspaceOrphaned(array $workspace, string $component, int $untilDate): bool
    {
        // If no component is specified, only workspaces with no component qualify
        if (empty($component) && !empty($workspace['component'])) {
            return false;
        }
        // If a component is specified, skip workspaces that don't match it
        if (!empty($component) && $workspace['component'] !== $component) {
            return false;
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
