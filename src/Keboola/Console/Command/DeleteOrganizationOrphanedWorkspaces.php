<?php
namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Keboola\ManageApi\ClientException;
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
    protected function configure()
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

    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $manageToken = $input->getArgument('manageToken');
        $organizationId = $input->getArgument('organizationId');
        $kbcUrl = sprintf('https://connection.%s', $input->getArgument('hostnameSuffix'));

        $manageClient = new Client(['token' => $manageToken, 'url' => $kbcUrl]);
        $organization = $manageClient->getOrganization($organizationId);
        $projects = $organization['projects'];
        $output->writeln(
            sprintf(
                'Checking workspaces for "%d" projects',
                count($projects)
            )
        );

        $storageUrl = 'https://connection.' . $input->getArgument('hostnameSuffix');

        $orphanComponent = $input->getArgument('orphanComponent');
        $componentDesc = empty($orphanComponent) ? '(empty/blank)' : $orphanComponent;
        $output->writeln(sprintf('Targeting workspaces with component: %s', $componentDesc));
        
        $force = $input->getOption('force');
        if ($force) {
            $output->writeln('Force option is set, doing it for real');
        } else {
            $output->writeln('This is just a dry-run, nothing will be actually deleted');
        }

        $totalWorkspaces = 0;
        $totalDeletedWorkspaces = 0;

        foreach ($projects as $project) {
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
                    $output->writeln(sprintf("WARN: Access denied to project: %s", $project['id']));
                    continue;
                }
            }
            $storageClient = new StorageApiClient([
                'token' => $storageToken['token'],
                'url' => $storageUrl,
                'logger' => new ConsoleLogger($output),
            ]);
            $devBranches = new DevBranches($storageClient);
            $branchesList = $devBranches->listBranches();

            $output->writeln(
                sprintf(
                    'Retrieving workspaces for project %s : %s ',
                    $project['id'],
                    $project['name']
                )
            );
            $totalProjectWorkspaces = 0;
            $totalProjectDeletedWorkspaces = 0;
            foreach ($branchesList as $branch) {
                $branchId = $branch['id'];
                $branchStorageClient = new BranchAwareClient($branchId, [
                    'token' => $storageToken['token'],
                    'url' => $storageUrl,
                    'backoffMaxTries' => 1,
                ]);
                $workspacesClient = new Workspaces($branchStorageClient);
                $workspaceList = $workspacesClient->listWorkspaces();
                $output->writeln('Found ' . count($workspaceList) . ' workspaces in branch ' . $branch['name']);
                $totalProjectWorkspaces += count($workspaceList);
                foreach ($workspaceList as $workspace) {
                    $shouldDropWorkspace = $this->isWorkspaceOrphaned(
                        $workspace,
                        $orphanComponent,
                        strtotime($input->getArgument('untilDate'))
                    );
                    if ($shouldDropWorkspace) {
                        $output->writeln('Deleting orphaned workspace ' . $workspace['id']);
                        $totalProjectDeletedWorkspaces ++;
                        if ($force) {
                            try {
                                $workspacesClient->deleteWorkspace($workspace['id']);
                            } catch (\Throwable $clientException) {
                                $output->writeln(
                                    sprintf(
                                        'Error deleting workspace %s:%s',
                                        (string) $workspace['id'],
                                        $clientException->getMessage()
                                    )
                                );
                            }
                        }
                    } else {
                        $output->writeln(
                            sprintf(
                                'Skipping %s workspace %s created on %s',
                                $workspace['component'],
                                (string) $workspace['id'],
                                $workspace['created']
                            )
                        );
                    }
                }
            }
            $output->writeln(
                sprintf(
                    'Project %s had a total of %d workspaces, %d were deleted.',
                    $project['id'],
                    $totalProjectWorkspaces,
                    $totalProjectDeletedWorkspaces
                )
            );
            $tokensClient = new Tokens($storageClient);
            $tokensClient->dropToken($storageToken['id']);

            $totalWorkspaces += $totalProjectWorkspaces;
            $totalDeletedWorkspaces += $totalProjectDeletedWorkspaces;
        }
        $output->writeln(
            sprintf(
                'A grand total of %d workspaces, and %d were deleted.',
                $totalWorkspaces,
                $totalDeletedWorkspaces
            )
        );
    }

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
        if (strtotime($workspace['created']) >= $untilDate) {
            return false;
        }
        // If all conditions pass, the workspace qualifies
        return true;
    }
}
