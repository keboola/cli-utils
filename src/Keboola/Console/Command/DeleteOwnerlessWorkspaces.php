<?php

namespace Keboola\Console\Command;

use Keboola\Sandboxes\Api\Client as SandboxesClient;
use Keboola\Sandboxes\Api\Exception\ClientException;
use Keboola\Sandboxes\Api\Sandbox;
use Keboola\StorageApi\Client as StorageApiClient;
use Keboola\StorageApi\Tokens;
use Keboola\StorageApi\Workspaces;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteOwnerlessWorkspaces extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('storage:delete-ownerless-workspaces')
            ->setDescription('Bulk delete workspaces that have inactive owner in this project.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Use [--force, -f] to do it for real.')
            ->addOption(
                'includeShared',
                null,
                InputOption::VALUE_NONE,
                'Use option --includeShared if you would also like to delete shared workspaces with inactive owner.',
            )
            ->addArgument(
                'storageToken',
                InputArgument::REQUIRED,
                'Keboola Storage API token to use'
            )
            ->addArgument(
                'hostnameSuffix',
                InputArgument::OPTIONAL,
                'Keboola Connection Hostname Suffix',
                'keboola.com'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token = $input->getArgument('storageToken');
        assert(is_string($token));
        $hostnameSuffix = $input->getArgument('hostnameSuffix');
        assert(is_string($hostnameSuffix));
        $url = 'https://connection.' . $hostnameSuffix;
        $sandboxesUrl = 'https://sandboxes.' . $hostnameSuffix;
        $includeShared = (bool) $input->getOption('includeShared');
        $force = (bool) $input->getOption('force');

        $storageClient = new StorageApiClient([
            'token' => $token,
            'url' => $url,
            'backoffMaxTries' => 1,
            'logger' => new ConsoleLogger($output),
        ]);
        $workspacesClient = new Workspaces($storageClient);
        $tokensClient = new Tokens($storageClient);
        $sandboxesClient = new SandboxesClient(
            $sandboxesUrl,
            $token
        );
        if ($force) {
            $output->writeln('Force option is set, doing it for real');
        } else {
            $output->writeln('This is just a dry-run, nothing will be actually deleted');
        }
        $totalDeletedSandboxes = 0;
        $totalDeletedStorageWorkspaces = 0;
        $sandboxes = $sandboxesClient->list();
        /** @var Sandbox $sandbox */
        foreach ($sandboxes as $sandbox) {
            try {
                $tokenId = $sandbox->getTokenId();
                if ($tokenId !== null) {
                    $tokensClient->getToken((int) $tokenId);
                    continue; // token exists so no need to do anything
                }
            } catch (\Throwable $exception) {
                if ($exception->getCode() !== 404) {
                    throw $exception;
                }
            }

            // check if we should skip shared sandboxes
            if (!$includeShared && $sandbox->getShared()) {
                continue;
            }

            if (!in_array($sandbox->getType(), Sandbox::CONTAINER_TYPES)) {
                // it is a database workspace
                if (empty($sandbox->getPhysicalId())) {
                    $output->writeln('No underlying storage workspace found for sandboxId ' . $sandbox->getId());
                } else {
                    $output->writeln('Deleting inactive storage workspace ' . $sandbox->getPhysicalId());
                    $totalDeletedStorageWorkspaces++;
                    if ($force) {
                        $this->deleteStorageWorkspace($workspacesClient, $sandbox->getPhysicalId(), $output);
                    }
                }
            } elseif (!empty($sandbox->getStagingWorkspaceId())) {
                $output->writeln('Deleting inactive staging storage workspace ' . $sandbox->getStagingWorkspaceId());
                $totalDeletedStorageWorkspaces++;
                if ($force) {
                    $this->deleteStorageWorkspace($workspacesClient, $sandbox->getStagingWorkspaceId(), $output);
                }
            }

            $totalDeletedSandboxes++;
            if ($force) {
                $sandboxesClient->delete($sandbox->getId());
            }
        }

        $output->writeln(sprintf(
            '%d sandboxes deleted and %d storage workspaces deleted',
            $totalDeletedSandboxes,
            $totalDeletedStorageWorkspaces
        ));

        return 0;
    }

    private function deleteStorageWorkspace(
        Workspaces $workspacesClient,
        string $workspaceId,
        OutputInterface $output
    ): void {
        try {
            $workspacesClient->deleteWorkspace((int) $workspaceId);
        } catch (\Throwable $clientException) {
            $output->writeln(
                sprintf(
                    'Error deleting workspace %s:%s',
                    $workspaceId,
                    $clientException->getMessage()
                )
            );
        }
    }
}
