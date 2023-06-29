<?php
namespace Keboola\Console\Command;

use Exception;
use DateTime;
use GuzzleHttp\Client;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client as StorageApiClient;
use Keboola\StorageApi\DevBranches;
use Keboola\StorageApi\Options\IndexOptions;
use Keboola\StorageApi\Workspaces;
use Psr\Http\Message\ResponseInterface;
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
            ->addArgument(
                'storageToken',
                InputArgument::REQUIRED,
                'Keboola Storage API token to use'
            )
            ->addArgument(
                'orphanComponents',
                InputArgument::IS_ARRAY,
                'Array list of components that qualify for orphanage.'
            )
            ->addArgument(
                'expirationTime',
                InputArgument::OPTIONAL,
                'String representation of date: default: \'-1 month\'',
                '-1 month'
            )
            ->addArgument(
                'hostnameSuffix',
                InputArgument::OPTIONAL,
                'Keboola Connection Hostname Suffix',
                'keboola.com'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $token = $input->getArgument('storageToken');

        $storageClient = new StorageApiClient([
            'token' => $token,
            'url' => 'https://connection.' . $input->getArgument('hostnameSuffix'),
        ]);
        $devBranches = new DevBranches($storageClient);
        $branchesList = $devBranches->listBranches();

        foreach ($branchesList as $branch) {
            $branchId = $branch['id'];
            $branchStorageClient = new BranchAwareClient($branchId);
            $workspacesClient = new Workspaces($branchStorageClient);
            $workspaceList = $workspacesClient->listWorkspaces();

            foreach ($workspaceList as $workspace) {
                $shouldDropWorkspace = $this->isWorkspaceOrphaned(
                    $workspace,
                    $input->getArgument('orphanComponents'),
                    strtotime($input->getArgument('expirationDate'))
                );
                if ($shouldDropWorkspace) {
                    $output->writeln('Deleting orphaned workspace ' . $workspace['id']);
                    $output->writeln('It was created on ' . $workspace['created']);
                    $workspacesClient->deleteWorkspace($workspace['id']);
                } else {
                    $output->writeln('Skipping workspace ' . $workspace['id']);
                    $output->writeln('It was created on ' . $workspace['created']);
                    $output->writeln('It is of type ' . $workspace['component']);
                }
            }
        }
    }

    private function isWorkspaceOrphaned(array $workspace, array $components, string $expirationDate): bool
    {
        return (in_array($workspace['component'], $components) && strtotime($workspace['created']) < $expirationDate);
    }
}
