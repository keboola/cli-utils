<?php

declare(strict_types=1);

namespace Keboola\Console\Command;


use Keboola\JobQueueClient\Client as JobQueueClient;
use Keboola\JobQueueClient\JobData;
use Keboola\ManageApi\Client;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

class MassProjectQueueMigration extends Command
{
    const ARGUMENT_MANAGE_TOKEN = 'manage-token';
    const ARGUMENT_CONNECTION_URL = 'connection-url';
    const ARGUMENT_SOURCE_FILE = 'source-file';

    const FEATURE_QUEUE_V2 = 'queuev2';
    const COMPONENT_QUEUE_MIGRATION_TOOL = 'keboola.queue-migration-tool';

    protected function configure()
    {
        $this
            ->setName('manage:mass-project-queue-migration')
            ->setDescription('Mass project migration to Queue v2')
            ->addArgument(self::ARGUMENT_MANAGE_TOKEN, InputArgument::REQUIRED, 'Manage token')
            ->addArgument(self::ARGUMENT_CONNECTION_URL, InputArgument::REQUIRED, 'Connection url')
            ->addArgument(self::ARGUMENT_SOURCE_FILE, InputArgument::REQUIRED, 'Source file with project ids')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $manageToken = $input->getArgument(self::ARGUMENT_MANAGE_TOKEN);
        $kbcUrl = $input->getArgument(self::ARGUMENT_CONNECTION_URL);
        $sourceFile = $input->getArgument(self::ARGUMENT_SOURCE_FILE);
        $output->writeln(sprintf('Fetching projects from "%s"', $sourceFile));

        $manageClient = new Client([
            'token' => $manageToken,
            'url' => $kbcUrl,
        ]);


        $logger = new ConsoleLogger($output);
        $queueApiUrl = str_replace('connection', 'queue', $kbcUrl);

        $projects = $this->parseProjectIds($sourceFile);
        $output->writeln(sprintf('Found "%s" projects', count($projects)));

        foreach ($projects as $projectId) {
            // set queuev2 project feature
            $projectRes = $manageClient->getProject($projectId);
            if (!in_array(self::FEATURE_QUEUE_V2, $projectRes['features'])) {
                $manageClient->addProjectFeature($projectId, self::FEATURE_QUEUE_V2);
            }

            $storageToken = $this->createStorageToken($manageClient, $projectId);
            $storageClient = new StorageClient([
                'token' => $storageToken,
                'url' => $kbcUrl,
            ]);
            $componentClient = new Components($storageClient);
            $configuration = new Configuration();
            $configuration
                ->setChangeDescription('Created configuration')
                ->setComponentId(self::COMPONENT_QUEUE_MIGRATION_TOOL)
                ->setName('Queue migration');

            $configuration = $componentClient->addConfiguration($configuration);

            try {
                $jobQueueClient = new JobQueueClient(
                    $logger,
                    $queueApiUrl,
                    $storageToken
                );
                $jobData = new JobData(
                    self::COMPONENT_QUEUE_MIGRATION_TOOL,
                    $configuration['id']
                );
                $jobRes = $jobQueueClient->createJob($jobData);
                $output->writeln(sprintf('Created migration job "%s"', $jobRes['id']));
            } catch (\Throwable $e) {
                $componentClient->deleteConfiguration(
                    self::COMPONENT_QUEUE_MIGRATION_TOOL,
                    $configuration['id']
                );

                throw $e;
            }
        }
    }

    private function createStorageToken(Client $client, string $projectId): string
    {
        $response = $client->createProjectStorageToken($projectId, [
            'description' => 'Cli utils - mass queue migration',
            'canManageBuckets' => true,
            'canReadAllFileUploads' => true,
            'canManageTokens' => true,
        ]);

        return $response['token'];
    }

    private function parseProjectIds(string $sourceFile): array
    {
        if (!file_exists($sourceFile)) {
            throw new \Exception(sprintf('Cannot open "%s"', $sourceFile));
        }
        $projectsText = trim(file_get_contents($sourceFile));
        if (!$projectsText) {
            return [];
        }

        return explode(PHP_EOL, $projectsText);
    }
}
