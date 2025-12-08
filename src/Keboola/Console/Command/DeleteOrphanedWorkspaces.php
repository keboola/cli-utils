<?php

namespace Keboola\Console\Command;

use InvalidArgumentException;
use Keboola\ManageApi\Client;
use Keboola\StorageApi\Client as StorageApiClient;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\DevBranches;
use Keboola\StorageApi\Workspaces;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteOrphanedWorkspaces extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('storage:delete-orphaned-workspaces')
            ->setDescription('Bulk delete orphaned workspaces of this project.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Use [--force, -f] to do it for real.')
            ->addOption('ignore-backend-errors', 'i', InputOption::VALUE_NONE, 'Use [--ignore-backend-errors, -i] to run delete using commands API. [--manage-token, -m] must be supplied for this action.')
            ->addOption('manage-token', 'm', InputOption::VALUE_OPTIONAL, 'Use [--manage-token, -m] to delete workspace via Command API. Manage token must be super admin token.')
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token = (string) $input->getArgument('storageToken');
        $componentToDelete = (string) $input->getArgument('orphanComponent');
        $isForce = (bool) $input->getOption('force');
        $ignoreBackendErrors = (bool) $input->getOption('ignore-backend-errors');
        $manageToken = $input->getOption('manage-token');
        if ($ignoreBackendErrors && !$manageToken) {
            throw new InvalidArgumentException('Manage token must be supplied for ignore-backend-errors.');
        }
        $hostnameSuffix = (string) $input->getArgument('hostnameSuffix');
        $url = 'https://connection.' . $hostnameSuffix;

        $storageClient = new StorageApiClient([
            'token' => $token,
            'url' => $url,
            'logger' => new ConsoleLogger($output),
        ]);
        $devBranches = new DevBranches($storageClient);
        $branchesList = $devBranches->listBranches();

        $untilDateStr = (string) $input->getArgument('untilDate');
        $untilDate = strtotime($untilDateStr);
        if ($untilDate === false) {
            throw new InvalidArgumentException(sprintf('Invalid date format: %s', $untilDateStr));
        }

        $output->writeln('Workspaces for component ' . $componentToDelete . ' will be deleted from ' . $url);

        if ($isForce) {
            $output->writeln('Force option is set, doing it for real');
        } else {
            $output->writeln('This is just a dry-run, nothing will be actually deleted');
        }
        $toDelete = [];
        $totalWorkspaces = 0;
        $totalDeletedWorkspaces = 0;
        foreach ($branchesList as $branch) {
            $branchId = $branch['id'];
            $branchStorageClient = $storageClient->getBranchAwareClient($branchId);
            $workspacesClient = new Workspaces($branchStorageClient);
            $workspaceList = $workspacesClient->listWorkspaces();
            $output->writeln('Found ' . count($workspaceList) . ' workspaces in branch ' . $branch['name']);
            $totalWorkspaces += count($workspaceList);
            foreach ($workspaceList as $workspace) {
                $shouldDropWorkspace = $this->isWorkspaceOrphaned(
                    $workspace,
                    $componentToDelete,
                    $untilDate
                );
                if ($shouldDropWorkspace) {
                    $output->writeln('Deleting orphaned workspace ' . $workspace['id']);
                    $output->writeln('It was created on ' . $workspace['created']);
                    $totalDeletedWorkspaces++;
                    if ($ignoreBackendErrors) {
                        $toDelete[] = $workspace['id'];
                    }
                    if ($isForce && !$ignoreBackendErrors) {
                        $output->writeln('Deleting orphaned workspace via SYNC API call' . $workspace['id']);
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
                    $output->writeln('Skipping workspace ' . $workspace['id']);
                    $output->writeln('It was created on ' . $workspace['created']);
                    $output->writeln('It is of type ' . $workspace['component']);
                }
            }
        }

        if ($ignoreBackendErrors && count($toDelete) > 0) {
            $parameters = [
                '--ids',
                implode(',', array_map(fn($i) => (string) $i, $toDelete)),
            ];
            if ($isForce) {
                $parameters[] = '--force';
            }
            $output->writeln('Deleting orphaned workspaces by command API "' . implode(' ', $toDelete) . '"');
            $manageClient = new Client([
                'token' => $manageToken,
                'url' => $url,
            ]);
            $response = $manageClient->runCommand([
                'command' => 'storage:workspace:drop-failed-workspaces-from-metadata',
                'parameters' => $parameters,
            ]);
            $output->writeln(sprintf(' - Command ID: %s', $response['commandExecutionId']));
        }

        $output->writeln(sprintf(
            'Of %d total workspaces found, %d were deleted.',
            $totalWorkspaces,
            $totalDeletedWorkspaces
        ));

        return 0;
    }

    /**
     * @param array<string, mixed> $workspace
     */
    private function isWorkspaceOrphaned(array $workspace, string $component, int $untilDate): bool
    {
        return ($workspace['component'] === $component) && strtotime($workspace['created']) < $untilDate;
    }
}
