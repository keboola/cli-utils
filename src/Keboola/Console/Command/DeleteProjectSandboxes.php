<?php

namespace Keboola\Console\Command;

use Keboola\Sandboxes\Api\Client as SandboxesClient;
use Keboola\Sandboxes\Api\Sandbox;
use Keboola\StorageApi\Client as StorageApiClient;
use Keboola\StorageApi\Exception;
use Keboola\StorageApi\Workspaces;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteProjectSandboxes extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('storage:delete-project-sandboxes')
            ->setDescription('Bulk delete project sandboxes.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Use [--force, -f] to do it for real.')
            ->addOption(
                'includeShared',
                null,
                InputOption::VALUE_NONE,
                'Use option --includeShared if you would also like to delete shared workspaces.',
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
        $token = (string) $input->getArgument('storageToken');
        $hostnameSuffix = (string) $input->getArgument('hostnameSuffix');
        $url = 'https://connection.' . $hostnameSuffix;
        $sandboxesUrl = 'https://sandboxes.' . $hostnameSuffix;
        $includeShared = (bool) $input->getOption('includeShared');
        $force = (bool) $input->getOption('force');

        $storageClient = new StorageApiClient([
            'token' => $token,
            'url' => $url,
        ]);
        $workspacesClient = new Workspaces($storageClient);
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
            // check if we should skip shared sandboxes
            if (!$includeShared && $sandbox->getShared()) {
                continue;
            }

            if ($sandbox->getDeletedTimestamp()) {
                $output->writeln('Skipping already deleted sandbox ' . $sandbox->getId());
                continue;
            }

            if (!in_array($sandbox->getType(), Sandbox::CONTAINER_TYPES)) {
                // it is a database workspace
                $output->writeln('Deleting storage workspace ' . $sandbox->getPhysicalId());
                if (!empty($sandbox->getPhysicalId())) {
                    if ($force) {
                        try {
                            $workspacesClient->deleteWorkspace($sandbox->getPhysicalId());
                            $totalDeletedStorageWorkspaces++;
                        } catch (Exception $exception) {
                            if ($exception->getCode() === 404) {
                                $output->writeln("Storage workspace not found");
                            }
                        }
                    }
                } else {
                    $output->writeln("No physical ID found, skipping");
                }
            } elseif (!empty($sandbox->getStagingWorkspaceId())) {
                $output->writeln('Deleting staging storage workspace ' . $sandbox->getPhysicalId());
                $totalDeletedStorageWorkspaces++;
                if ($force) {
                    $workspacesClient->deleteWorkspace($sandbox->getStagingWorkspaceId(), [], true);
                }
            }

            $totalDeletedSandboxes++;
            $output->writeln('Deleting sandbox ' . $sandbox->getId());
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
}
