<?php
namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client as StorageApiClient;
use Keboola\StorageApi\DevBranches;
use Keboola\StorageApi\Tokens;
use Keboola\StorageApi\Workspaces;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
                'Component that qualify for orphanage (ex. keboola.snowflake-transformation).'
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

        $totalWorkspaces = 0;
        $totalDeletedWorkspaces = 0;

        foreach ($projects as $project) {
            $storageToken = $manageClient->createProjectStorageToken(
                $project['id'],
                ['description' => 'Maintenance Workspace Cleaner']
            );
            $storageClient = new StorageApiClient([
                'token' => $storageToken['token'],
                'url' => $storageUrl,
            ]);
            $devBranches = new DevBranches($storageClient);
            $branchesList = $devBranches->listBranches();

            $force = $input->getOption('force');
            if ($force) {
                $output->writeln('Force option is set, doing it for real');
            } else {
                $output->writeln('This is just a dry-run, nothing will be actually deleted');
            }

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
                ]);
                $workspacesClient = new Workspaces($branchStorageClient);
                $workspaceList = $workspacesClient->listWorkspaces();
                $output->writeln('Found ' . count($workspaceList) . ' workspaces in branch ' . $branch['name']);
                $totalProjectWorkspaces += count($workspaceList);
                foreach ($workspaceList as $workspace) {
                    $shouldDropWorkspace = $this->isWorkspaceOrphaned(
                        $workspace,
                        $input->getArgument('orphanComponent'),
                        strtotime($input->getArgument('untilDate'))
                    );
                    if ($shouldDropWorkspace) {
                        $output->writeln('Deleting orphaned workspace ' . $workspace['id']);
                        $output->writeln('It was created on ' . $workspace['created']);
                        $totalProjectDeletedWorkspaces ++;
                        if ($force) {
                            $workspacesClient->deleteWorkspace($workspace['id']);
                        }
                    } else {
                        $output->writeln('Skipping workspace ' . $workspace['id']);
                        $output->writeln('It was created on ' . $workspace['created']);
                        $output->writeln('It is of type ' . $workspace['component']);
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
        return ($workspace['component'] === $component) && strtotime($workspace['created']) < $untilDate;
    }
}
