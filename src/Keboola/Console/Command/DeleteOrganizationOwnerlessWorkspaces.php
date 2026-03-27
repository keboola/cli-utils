<?php

namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Keboola\Sandboxes\Api\Client as SandboxesClient;
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

class DeleteOrganizationOwnerlessWorkspaces extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('manage:delete-organization-ownerless-workspaces')
            ->setDescription('Bulk delete ownerless workspaces (sandboxes with inactive token owner) across all projects in an organization.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Use [--force, -f] to do it for real.')
            ->addOption(
                'includeShared',
                null,
                InputOption::VALUE_NONE,
                'Use option --includeShared if you would also like to delete shared workspaces with inactive owner.',
            )
            ->addArgument(
                'manageToken',
                InputArgument::REQUIRED,
                'Keboola Manage API token to use',
            )
            ->addArgument(
                'organizationId',
                InputArgument::REQUIRED,
                'ID of the organization to clean',
            )
            ->addArgument(
                'hostnameSuffix',
                InputArgument::OPTIONAL,
                'Keboola Connection Hostname Suffix',
                'keboola.com',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manageToken = $input->getArgument('manageToken');
        assert(is_string($manageToken));
        $organizationId = $input->getArgument('organizationId');
        assert(is_string($organizationId));
        $organizationId = (int) $organizationId;
        $hostnameSuffix = $input->getArgument('hostnameSuffix');
        assert(is_string($hostnameSuffix));

        $kbcUrl = sprintf('https://connection.%s', $hostnameSuffix);
        $sandboxesUrl = sprintf('https://sandboxes.%s', $hostnameSuffix);

        $includeShared = (bool) $input->getOption('includeShared');
        $force = (bool) $input->getOption('force');

        $manageClient = new Client(['token' => $manageToken, 'url' => $kbcUrl]);
        $organization = $manageClient->getOrganization($organizationId);
        $projects = $organization['projects'];

        $output->writeln(sprintf('Checking workspaces for "%d" projects', count($projects)));

        if ($force) {
            $output->writeln('Force option is set, doing it for real');
        } else {
            $output->writeln('This is just a dry-run, nothing will be actually deleted');
        }

        $totalDeletedSandboxes = 0;
        $totalDeletedStorageWorkspaces = 0;
        /** @var array<int|string, array<int, array{sandboxId: string, physicalId: string, tokenId: string}>> $summary */
        $summary = [];

        foreach ($projects as $project) {
            try {
                $storageToken = $manageClient->createProjectStorageToken(
                    $project['id'],
                    [
                        'description' => 'Maintenance Ownerless Workspace Cleaner',
                        'expiresIn' => 1800,
                        'canManageTokens' => true,
                    ],
                );
            } catch (\Throwable $e) {
                if ($e->getCode() === 403) {
                    $output->writeln(sprintf('WARN: Access denied to project: %s', $project['id']));
                    continue;
                }
                throw $e;
            }

            $output->writeln(sprintf(
                'Processing project %s : %s',
                $project['id'],
                $project['name'],
            ));

            $storageClient = new StorageApiClient([
                'token' => $storageToken['token'],
                'url' => $kbcUrl,
                'backoffMaxTries' => 1,
                'logger' => new ConsoleLogger($output),
            ]);
            $workspacesClient = new Workspaces($storageClient);
            $tokensClient = new Tokens($storageClient);
            $sandboxesClient = new SandboxesClient(
                $sandboxesUrl,
                $storageToken['token'],
            );

            $projectDeletedSandboxes = 0;
            $projectDeletedStorageWorkspaces = 0;
            $projectKey = sprintf('%s (%s)', $project['name'], $project['id']);
            $summary[$projectKey] = [];

            $sandboxes = $sandboxesClient->list();
            $workingTokens = 0;
            /** @var Sandbox $sandbox */
            foreach ($sandboxes as $sandbox) {
                try {
                    $tokenId = $sandbox->getTokenId();
                    if ($tokenId !== null) {
                        $tokensClient->getToken((int) $tokenId);
                        $workingTokens++;
                        $output->writeln('Working token ' . $tokenId);
                        continue; // token exists so no need to do anything
                    }
                } catch (\Throwable $exception) {
                    if (!in_array($exception->getCode(), [403, 404])) {
                        throw $exception;
                    }
                }

                if (!$includeShared && $sandbox->getShared()) {
                    continue;
                }

                $physicalId = '';
                if (!in_array($sandbox->getType(), Sandbox::CONTAINER_TYPES)) {
                    if (empty($sandbox->getPhysicalId())) {
                        $output->writeln('No underlying storage workspace found for sandboxId ' . $sandbox->getId());
                    } else {
                        $physicalId = $sandbox->getPhysicalId();
                        $output->writeln('Deleting inactive storage workspace ' . $physicalId);
                        $projectDeletedStorageWorkspaces++;
                        if ($force) {
                            $this->deleteStorageWorkspace($workspacesClient, $physicalId, $output);
                        }
                    }
                } elseif (!empty($sandbox->getStagingWorkspaceId())) {
                    $physicalId = $sandbox->getStagingWorkspaceId();
                    $output->writeln('Deleting inactive staging storage workspace ' . $physicalId);
                    $projectDeletedStorageWorkspaces++;
                    if ($force) {
                        $this->deleteStorageWorkspace($workspacesClient, $physicalId, $output);
                    }
                }

                $summary[$projectKey][] = [
                    'sandboxId' => $sandbox->getId(),
                    'physicalId' => $physicalId,
                    'tokenId' => (string) ($sandbox->getTokenId() ?? ''),
                ];

                $projectDeletedSandboxes++;
                if ($force) {
                    $sandboxesClient->delete($sandbox->getId());
                }
            }

            $output->writeln('Working tokens ' . $workingTokens);

            $output->writeln(sprintf(
                'Project %s: %d sandboxes deleted, %d storage workspaces deleted',
                $project['id'],
                $projectDeletedSandboxes,
                $projectDeletedStorageWorkspaces,
            ));

            try {
                $tokensClient->dropToken($storageToken['id']);
            } catch (\Throwable $e) {
                $output->writeln(sprintf(
                    'WARN: Could not drop temporary token %s: %s',
                    $storageToken['id'],
                    $e->getMessage(),
                ));
            }

            $totalDeletedSandboxes += $projectDeletedSandboxes;
            $totalDeletedStorageWorkspaces += $projectDeletedStorageWorkspaces;
        }

        // Print summary
        $output->writeln('');
        $output->writeln(sprintf('=== Summary for organization %s ===', $organization['name'] ?? $organizationId));
        foreach ($summary as $projectKey => $workspaces) {
            if (count($workspaces) === 0) {
                continue;
            }
            $output->writeln(sprintf('  Project: %s', $projectKey));
            foreach ($workspaces as $workspace) {
                $output->writeln(sprintf(
                    '    - SandboxId: %s, PhysicalId: %s, TokenId: %s',
                    $workspace['sandboxId'],
                    $workspace['physicalId'] ?: '(none)',
                    $workspace['tokenId'] ?: '(none)',
                ));
            }
        }
        $output->writeln('');
        $output->writeln(sprintf(
            'Grand total: %d sandboxes deleted and %d storage workspaces deleted',
            $totalDeletedSandboxes,
            $totalDeletedStorageWorkspaces,
        ));

        return 0;
    }

    private function deleteStorageWorkspace(
        Workspaces $workspacesClient,
        string $workspaceId,
        OutputInterface $output,
    ): void {
        try {
            $workspacesClient->deleteWorkspace((int) $workspaceId);
        } catch (\Throwable $clientException) {
            $output->writeln(sprintf(
                'Error deleting workspace %s:%s',
                $workspaceId,
                $clientException->getMessage(),
            ));
        }
    }
}
