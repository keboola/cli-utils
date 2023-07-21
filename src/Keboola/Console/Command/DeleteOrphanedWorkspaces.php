<?php
namespace Keboola\Console\Command;

use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client as StorageApiClient;
use Keboola\StorageApi\DevBranches;
use Keboola\StorageApi\Workspaces;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteOrphanedWorkspaces extends Command
{
    protected function configure()
    {
        $this
            ->setName('storage:delete-orphaned-workspaces')
            ->setDescription('Bulk delete orphaned workspaces of this project.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Use [--force, -f] to do it for real.')
            ->addArgument(
                'storageToken',
                InputArgument::REQUIRED,
                'Keboola Storage API token to use'
            )
            ->addArgument(
                'orphanComponent',
                InputArgument::REQUIRED,
                'Array list of components that qualify for orphanage.'
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
        $token = $input->getArgument('storageToken');
        $url = 'https://connection.' . $input->getArgument('hostnameSuffix');

        $storageClient = new StorageApiClient([
            'token' => $token,
            'url' => $url,
        ]);
        $devBranches = new DevBranches($storageClient);
        $branchesList = $devBranches->listBranches();

        if ($input->getOption('force')) {
            $output->writeln('Force option is set, doing it for real');
        } else {
            $output->writeln('This is just a dry-run, nothing will be actually deleted');
        }
        $totalWorkspaces = 0;
        $totalDeletedWorkspaces = 0;
        foreach ($branchesList as $branch) {
            $branchId = $branch['id'];
            $branchStorageClient = new BranchAwareClient($branchId, [
                'token' => $token,
                'url' => $url,
            ]);
            $workspacesClient = new Workspaces($branchStorageClient);
            $workspaceList = $workspacesClient->listWorkspaces();
            $output->writeln('Found ' . count($workspaceList) . ' workspaces in branch ' . $branch['name']);
            $totalWorkspaces += count($workspaceList);
            foreach ($workspaceList as $workspace) {
                $shouldDropWorkspace = $this->isWorkspaceOrphaned(
                    $workspace,
                    $input->getArgument('orphanComponent'),
                    strtotime($input->getArgument('untilDate'))
                );
                if ($shouldDropWorkspace) {
                    $output->writeln('Deleting orphaned workspace ' . $workspace['id']);
                    $output->writeln('It was created on ' . $workspace['created']);
                    $totalDeletedWorkspaces ++;
                    if ($input->getOption('force')) {
                        $workspacesClient->deleteWorkspace($workspace['id']);
                    }
                } else {
                    $output->writeln('Skipping workspace ' . $workspace['id']);
                    $output->writeln('It was created on ' . $workspace['created']);
                    $output->writeln('It is of type ' . $workspace['component']);
                }
            }
        }
        $output->writeln(sprintf(
            'Of %d total workspaces found, %d were deleted.',
            $totalWorkspaces,
            $totalDeletedWorkspaces
        ));
    }

    private function isWorkspaceOrphaned(array $workspace, string $component, int $untilDate): bool
    {
        return ($workspace['component'] === $component) && strtotime($workspace['created']) < $untilDate;
    }
}
