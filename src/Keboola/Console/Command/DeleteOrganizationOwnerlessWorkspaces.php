<?php

namespace Keboola\Console\Command;

use Keboola\JobQueueClient\Client as JobQueueClient;
use Keboola\JobQueueClient\JobData;
use Keboola\ManageApi\Client;
use Keboola\SandboxesServiceApiClient\ApiClientConfiguration;
use Keboola\SandboxesServiceApiClient\Apps\AppsApiClient;
use Keboola\ServiceClient\ServiceClient;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client as StorageApiClient;
use Keboola\StorageApi\ClientException as StorageClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use Keboola\StorageApi\Tokens;
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
            ->setDescription('Bulk delete ownerless workspaces (sessions with inactive user) across all projects in an organization.')
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
        if (!ctype_digit($organizationId)) {
            throw new \InvalidArgumentException('Argument "organizationId" must be a numeric string.');
        }
        $organizationId = (int) $organizationId;
        $hostnameSuffix = $input->getArgument('hostnameSuffix');
        assert(is_string($hostnameSuffix));
        assert($hostnameSuffix !== '');

        $kbcUrl = sprintf('https://connection.%s', $hostnameSuffix);
        $editorUrl = sprintf('https://editor.%s', $hostnameSuffix);
        $serviceClient = new ServiceClient($hostnameSuffix);

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

        $totalDeleted = 0;
        /** @var array<int|string, array<int, array{sessionId: string, componentId: string, configurationId: string, userId: string}>> $summary */
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
            $tokensClient = new Tokens($storageClient);
            $editorClient = new EditorServiceClient($editorUrl, $storageToken['token']);

            // Build a set of active user IDs and token IDs from project tokens
            $activeUserIds = [];
            $activeTokenIds = [];
            foreach ($tokensClient->listTokens() as $projectToken) {
                $activeTokenIds[$projectToken['id']] = true;
                if (isset($projectToken['admin']['id'])) {
                    $activeUserIds[$projectToken['admin']['id']] = true;
                }
            }

            $projectDeleted = 0;
            $projectKey = sprintf('%s (%s)', $project['name'], $project['id']);
            $summary[$projectKey] = [];

            foreach ($editorClient->listSessions() as $session) {
                if (isset($activeUserIds[$session['userId']])) {
                    if ($output->isVerbose()) {
                        $output->writeln('Active user ' . $session['userId']);
                    }
                    continue;
                }

                if (!$includeShared && $session['shared']) {
                    $output->writeln(sprintf(
                        'Skipping shared session %s/%s for session %s',
                        $session['componentId'],
                        $session['configurationId'],
                        $session['id'],
                    ));
                    continue;
                }

                $output->writeln(sprintf(
                    'Deleting configuration %s/%s (branch %s) for session %s',
                    $session['componentId'],
                    $session['configurationId'],
                    $session['branchId'],
                    $session['id'],
                ));

                $summary[$projectKey][] = [
                    'sessionId' => $session['id'],
                    'componentId' => $session['componentId'],
                    'configurationId' => $session['configurationId'],
                    'userId' => $session['userId'],
                ];

                $projectDeleted++;
                if ($force) {
                    $branchClient = new BranchAwareClient($session['branchId'], [
                        'token' => $storageToken['token'],
                        'url' => $kbcUrl,
                    ]);
                    $components = new Components($branchClient);
                    try {
                        // First call moves the configuration to trash, second call permanently purges it.
                        $components->deleteConfiguration($session['componentId'], $session['configurationId']);
                        $components->deleteConfiguration($session['componentId'], $session['configurationId']);
                    } catch (StorageClientException $e) {
                        if ($e->getStringCode() !== 'storage.components.cannotDeleteConfiguration') {
                            throw $e;
                        }
                        $editorClient->deleteSession($session['id']);
                    }
                }
            }

            // Handle Python/R sandboxes via sandbox-service
            $appsClient = new AppsApiClient(new ApiClientConfiguration(
                baseUrl: $serviceClient->getSandboxesServiceUrl(),
                storageToken: $storageToken['token'],
                userAgent: 'Keboola CLI Utils',
            ));

            $storageComponents = new Components($storageClient);
            $sandboxConfigMap = [];
            foreach ($storageComponents->listComponentConfigurations(
                (new ListComponentConfigurationsOptions())->setComponentId('keboola.sandboxes'),
            ) as $config) {
                $sandboxConfigMap[$config['id']] = [
                    'creatorTokenId' => $config['creatorToken']['id'] ?? null,
                    'shared' => (bool) ($config['configuration']['runtime']['shared'] ?? false),
                ];
            }

            $queueClient = new JobQueueClient($serviceClient->getQueueUrl(), $storageToken['token']);

            foreach ($appsClient->listApps(types: ['python', 'r']) as $app) {
                $configInfo = $sandboxConfigMap[$app->getConfigId()] ?? null;
                $creatorTokenId = $configInfo['creatorTokenId'] ?? null;
                if ($creatorTokenId !== null && isset($activeTokenIds[$creatorTokenId])) {
                    if ($output->isVerbose()) {
                        $output->writeln('Active token for app ' . $app->getId());
                    }
                    continue;
                }

                if (!$includeShared && ($configInfo['shared'] ?? false)) {
                    $output->writeln(sprintf(
                        'Skipping shared sandbox config keboola.sandboxes/%s for app %s',
                        $app->getConfigId(),
                        $app->getId(),
                    ));
                    continue;
                }

                $output->writeln(sprintf(
                    'Deleting sandbox config keboola.sandboxes/%s (branch %s) for app %s',
                    $app->getConfigId(),
                    $app->getBranchId() ?? 'default',
                    $app->getId(),
                ));

                $summary[$projectKey][] = [
                    'sessionId' => $app->getId(),
                    'componentId' => 'keboola.sandboxes',
                    'configurationId' => $app->getConfigId(),
                    'userId' => (string) ($configInfo['creatorTokenId'] ?? ''),
                ];

                $projectDeleted++;
                if ($force) {
                    try {
                        $queueClient->createJob(new JobData(
                            componentId: 'keboola.sandboxes',
                            configId: $app->getConfigId(),
                            configData: [
                                'parameters' => [
                                    'task' => 'delete',
                                    'id' => $app->getId(),
                                ],
                            ],
                            branchId: $app->getBranchId(),
                        ));
                    } catch (\Throwable $e) {
                        $output->writeln(sprintf(
                            'WARN: Job creation failed for app %s, falling back to direct deletion: %s',
                            $app->getId(),
                            $e->getMessage(),
                        ));
                        $appsClient->deleteApp($app->getId());
                        try {
                            // First call moves the configuration to trash, second call permanently purges it.
                            $storageComponents->deleteConfiguration('keboola.sandboxes', $app->getConfigId());
                            $storageComponents->deleteConfiguration('keboola.sandboxes', $app->getConfigId());
                        } catch (StorageClientException $e) {
                            if ($e->getStringCode() !== 'storage.components.cannotDeleteConfiguration') {
                                throw $e;
                            }
                        }
                    }
                }
            }

            $output->writeln(sprintf(
                'Project %s: %d sessions/apps deleted',
                $project['id'],
                $projectDeleted,
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

            $totalDeleted += $projectDeleted;
        }

        // Print summary
        $output->writeln('');
        $output->writeln(sprintf('=== Summary for organization %s ===', $organization['name'] ?? $organizationId));
        foreach ($summary as $projectKey => $sessions) {
            if (count($sessions) === 0) {
                continue;
            }
            $output->writeln(sprintf('  Project: %s', $projectKey));
            foreach ($sessions as $session) {
                $output->writeln(sprintf(
                    '    - SessionId: %s, Configuration: %s/%s, UserId: %s',
                    $session['sessionId'],
                    $session['componentId'],
                    $session['configurationId'],
                    $session['userId'] ?: '(none)',
                ));
            }
        }
        $output->writeln('');
        $output->writeln(sprintf(
            'Grand total: %d sessions/apps deleted',
            $totalDeleted,
        ));

        return 0;
    }
}
