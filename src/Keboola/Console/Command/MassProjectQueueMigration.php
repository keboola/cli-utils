<?php

declare(strict_types=1);

namespace Keboola\Console\Command;

use GuzzleHttp\Client as GuzzleClient;
use Keboola\JobQueueClient\Client as JobQueueClient;
use Keboola\JobQueueClient\Exception\ClientException as JobQueueClientException;
use Keboola\JobQueueClient\JobData;
use Keboola\ManageApi\Client;
use Keboola\ManageApi\ClientException as ManageClientException;
use Keboola\StorageApi\Client as StorageClient;
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
    const JOB_STATES_FINAL = ['success', 'error', 'terminated', 'cancelled'];

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
        $output->writeln(sprintf('Migrating "%s" projects', count($projects)));

        $migrationJobs = [];
        foreach ($projects as $projectId) {
            // set queuev2 project feature
            try {
                $projectRes = $manageClient->getProject($projectId);
                if (in_array(self::FEATURE_QUEUE_V2, $projectRes['features'])) {
                    // don't migrate project with feature already set
                    $output->writeln(sprintf('Project "%s" is already on Queue v2', $projectId));
                    continue;
                }
                $manageClient->addProjectFeature($projectId, self::FEATURE_QUEUE_V2);
                $storageToken = $this->createStorageToken($manageClient, $projectId);
            } catch (ManageClientException $e) {
                $output->writeln(sprintf(
                    'Exception occurred while accessing project %s: %s',
                    $projectId,
                    $e->getMessage()
                ));

                continue;
            }

            $storageClient = new StorageClient([
                'url' => $kbcUrl,
                'token' => $storageToken,
            ]);
            $encryptionUrl = $storageClient->getServiceUrl('encryption');
            $encryptedManageToken = $this->encrypt($encryptionUrl, $manageToken);

            $jobData = new JobData(
                self::COMPONENT_QUEUE_MIGRATION_TOOL,
                '',
                [
                    'parameters' => [
                        '#manageToken' => $encryptedManageToken
                    ],
                ]
            );

            $jobQueueClient = new JobQueueClient(
                $logger,
                $queueApiUrl,
                $storageToken
            );

            try {
                $jobRes = $jobQueueClient->createJob($jobData);
            } catch (JobQueueClientException $e) {
                $output->writeln(sprintf(
                    'Exception occurred while creating migration job in project %s: %s',
                    $projectId,
                    $e->getMessage()
                ));

                continue;
            }

            $output->writeln(sprintf(
                'Created migration job "%s" for project "%s"',
                $jobRes['id'],
                $projectId
            ));
            $migrationJobs[$jobRes['id']] = [
                'jobId' => $jobRes['id'],
                'projectId' => $projectId,
                'jobQueueClient' => $jobQueueClient,
                'storageToken' => $storageToken,
            ];
        }

        if (empty($migrationJobs)) {
            $output->writeln('Skipping migration');
            $output->writeln(PHP_EOL);
            return;
        }

        $output->writeln(sprintf('Created %s migration jobs in total', count($migrationJobs)));
        $output->writeln('Waiting for the jobs to finish...');

        // wait until all migration jobs are finished
        $unfinishedJobs = $migrationJobs;

        while (count($unfinishedJobs) > 0) {
            foreach ($unfinishedJobs as $jobId => $data) {
                /** @var JobQueueClient $jobQueueClient */
                $jobQueueClient = $data['jobQueueClient'];
                $jobRes = $jobQueueClient->getJob((string) $jobId);
                if (in_array($jobRes['status'], self::JOB_STATES_FINAL)) {
                    unset($unfinishedJobs[$jobId]);
                    unset($migrationJobs[$jobId]['jobQueueClient']);
                    $migrationJobs[$jobId]['status'] = $jobRes['status'];
                }
            }
            sleep(2);
        }

        $successJobs = array_filter($migrationJobs, fn($item) => $item['status'] === 'success');
        $errorJobs = array_filter($migrationJobs, fn($item) => $item['status'] === 'error');
        $terminatedJobs = array_filter(
            $migrationJobs,
            fn($item) => $item['status'] === 'terminated' || $item['status'] === 'cancelled'
        );

        $output->writeln(sprintf('%s migration jobs finished successfully', count($successJobs)));

        $output->writeln(sprintf('%s migration jobs ended with error:', count($errorJobs)));
        foreach ($errorJobs as $errorJob) {
            $output->writeln(sprintf(
                'Job "%s" of project "%s" ended with error',
                $errorJob['jobId'],
                $errorJob['projectId']
            ));

            $manageClient->removeProjectFeature($errorJob['projectId'], self::FEATURE_QUEUE_V2);
        }

        $output->writeln(sprintf('%s migration jobs were terminated or cancelled:', count($terminatedJobs)));
        foreach ($terminatedJobs as $terminatedJob) {
            $output->writeln(sprintf(
                'Job "%s" of project "%s" ended with "%s"',
                $terminatedJob['jobId'],
                $terminatedJob['projectId'],
                $terminatedJob['status']
            ));
        }
        $output->writeln(PHP_EOL);
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

    private function encrypt(
        string $encryptionApiUrl,
        string $value
    ): string {
        $client = new GuzzleClient();
        $response = $client->post(
            sprintf('%s/encrypt?componentId=%s', $encryptionApiUrl, self::COMPONENT_QUEUE_MIGRATION_TOOL),
            [
                'body' => $value,
                'headers' => [
                    'Content-Type' => 'text/plain'
                ],
            ]
        );

        return $response->getBody()->getContents();
    }
}
