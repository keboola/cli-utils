<?php

namespace Keboola\Console\Command;

use Keboola\JobQueueClient\Client as JobQueueClient;
use Keboola\JobQueueClient\JobData;
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
        assert($token !== '');
        $hostnameSuffix = $input->getArgument('hostnameSuffix');
        assert(is_string($hostnameSuffix));
        assert($hostnameSuffix !== '');
        $url = 'https://connection.' . $hostnameSuffix;
        $editorUrl = 'https://editor.' . $hostnameSuffix;
        $includeShared = (bool) $input->getOption('includeShared');
        $force = (bool) $input->getOption('force');

        $storageClient = new StorageApiClient([
            'token' => $token,
            'url' => $url,
            'backoffMaxTries' => 1,
            'logger' => new ConsoleLogger($output),
        ]);
        $tokensClient = new Tokens($storageClient);
        $editorClient = new EditorServiceClient($editorUrl, $token);

        if ($force) {
            $output->writeln('Force option is set, doing it for real');
        } else {
            $output->writeln('This is just a dry-run, nothing will be actually deleted');
        }

        // Build a set of active user IDs and token IDs from project tokens
        $activeUserIds = [];
        $activeTokenIds = [];
        foreach ($tokensClient->listTokens() as $projectToken) {
            $activeTokenIds[$projectToken['id']] = true;
            if (isset($projectToken['admin']['id'])) {
                $activeUserIds[$projectToken['admin']['id']] = true;
            }
        }

        $totalDeleted = 0;

        foreach ($editorClient->listSessions() as $session) {
            if (isset($activeUserIds[$session['userId']])) {
                continue; // user is still active
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

            $branchId = $session['branchId'];
            $componentId = $session['componentId'];
            $configurationId = $session['configurationId'];
            $sessionId = $session['id'];

            $output->writeln(sprintf(
                'Deleting configuration %s/%s (branch %s) for session %s',
                $componentId,
                $configurationId,
                $branchId,
                $sessionId,
            ));

            $totalDeleted++;
            if ($force) {
                $branchClient = new BranchAwareClient($branchId, [
                    'token' => $token,
                    'url' => $url,
                ]);
                $components = new Components($branchClient);
                try {
                    // First call moves the configuration to trash, second call permanently purges it.
                    $components->deleteConfiguration($componentId, $configurationId);
                    $components->deleteConfiguration($componentId, $configurationId);
                } catch (StorageClientException $e) {
                    if ($e->getStringCode() !== 'storage.components.cannotDeleteConfiguration') {
                        throw $e;
                    }
                    $editorClient->deleteSession($sessionId);
                }
            }
        }

        // Handle Python/R sandboxes via sandbox-service
        $serviceClient = new ServiceClient($hostnameSuffix);
        $appsClient = new AppsApiClient(new ApiClientConfiguration(
            baseUrl: $serviceClient->getSandboxesServiceUrl(),
            storageToken: $token,
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

        $queueClient = new JobQueueClient($serviceClient->getQueueUrl(), $token);

        foreach ($appsClient->listApps(types: ['python', 'r']) as $app) {
            $configInfo = $sandboxConfigMap[$app->getConfigId()] ?? null;
            $creatorTokenId = $configInfo['creatorTokenId'] ?? null;
            if ($creatorTokenId !== null && isset($activeTokenIds[$creatorTokenId])) {
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

            $totalDeleted++;
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

        $output->writeln(sprintf('%d sessions/apps deleted', $totalDeleted));

        return 0;
    }
}
