<?php

declare(strict_types=1);

namespace Keboola\Console\Command;

use Exception;
use Keboola\Orchestrator\Client as OrchestratorClient;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\Options\IndexOptions;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CheckProjectBeforeQueueMigration extends Command
{
    const ARGUMENT_STORAGE_TOKEN = 'storage-token';
    const ARGUMENT_CONNECTION_URL = 'connection-url';

    protected function configure()
    {
        $this
            ->setName('manage:check-project-before-queue-migration')
            ->setDescription('Check project before migration to Queue v2')
            ->addArgument(self::ARGUMENT_STORAGE_TOKEN, InputArgument::REQUIRED, 'Storage token')
            ->addArgument(self::ARGUMENT_CONNECTION_URL, InputArgument::REQUIRED, 'Connection url');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $storageToken = $input->getArgument(self::ARGUMENT_STORAGE_TOKEN);
        $kbcUrl = $input->getArgument(self::ARGUMENT_CONNECTION_URL);
        $triggers = $this->getOrchestrationTriggers($kbcUrl, $storageToken);

        $output->writeln(sprintf('Found %s orchestrations with triggers:', count($triggers)));
        $output->writeln('-----');
        foreach ($triggers as $configId => $configTriggers) {
            $output->writeln(sprintf('Configuration ID %s:', $configId));
            foreach ($configTriggers as $configTrigger) {
                $output->writeln(sprintf('Trigger ID %s:', $configTrigger['id']));
                $output->writeln(sprintf('Trigger runWithTokenId %s:', $configTrigger['runWithTokenId']));
            }
            $output->writeln('-----');
        }
    }

    private function getOrchestrationTriggers(string $kbcUrl, string $storageToken): array
    {
        $storageClient = new StorageClient([
            'url' => $kbcUrl,
            'token' => $storageToken,
        ]);

        $orchestratorClient = OrchestratorClient::factory([
            'url' => $this->findOrchestratorServiceUrl($storageClient),
            'token' => $storageToken,
        ]);

        $orchestrations = $orchestratorClient->getOrchestrations();

        $triggers = [];
        foreach ($orchestrations as $orchestration) {
            $foundTriggers = $storageClient->listTriggers([
                'component' => 'orchestrator',
                'configuration' => $orchestration['id'],
            ]);

            if (count($foundTriggers) > 0) {
                $triggers[$orchestration['id']] = $foundTriggers;
            }
        }

        return $triggers;
    }

    private function findOrchestratorServiceUrl(StorageClient $storageClient): string
    {
        $index = $storageClient->indexAction((new IndexOptions())->setExclude(['components']));
        $serviceUrl = null;
        foreach ($index['services'] as $service) {
            if ($service['id'] === 'syrup') {
                $serviceUrl = $service['url'];
            }
        }

        if (!$serviceUrl) {
            throw new Exception('Legacy Orchestrator url not found in the services list.');
        }

        return $serviceUrl . '/orchestrator/';
    }
}
