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
            ->addArgument('hostnameSuffix', InputArgument::OPTIONAL, 'Keboola Connection Hostname Suffix', 'keboola.com')
            ->addArgument('orphanComponents', InputArgument::IS_ARRAY, 'Array list of components that qualify for orphanage.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $token = getenv('STORAGE_API_TOKEN');
        if (!$token) {
            throw new Exception('Environment variable "STORAGE_API_TOKEN" missing.');
        }

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
                if ($this->isWorkspaceOrphaned($workspace, $input->getArgument('orphanComponents'), new DateTime(now()))) {
                    if (new \DateTime($workspace['created']) < new \DateTime('2 weeks ago')) {
                        echo 'Deleting orphaned workspace ' . $workspace['id']  . "\n";
                        echo 'It was created on ' . $workspace['created'] . "\n";
                        $workspacesClient->deleteWorkspace($workspace['id']);
                    } else {
                        echo 'Skipping workspace ' . $workspace['id']  . "\n";
                        echo 'It was created on ' . $workspace['created'] . "\n";
                        echo 'It is of type ' . $workspace['component'] . "\n";
                    }
                }
            }
        }
    }

    private function isWorkspaceOrphaned(array $workspace, array $components, string $expirationDate): bool
    {
        return (in_array($workspace['component'], $components) && $workspace['created'] < $expirationDate);
    }
}
