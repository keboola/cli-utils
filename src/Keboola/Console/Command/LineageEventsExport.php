<?php
namespace Keboola\Console\Command;

use Exception;
use GuzzleHttp\Client;
use Keboola\StorageApi\Client as StorageApiClient;
use Keboola\StorageApi\Options\IndexOptions;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LineageEventsExport extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('storage:lineage-events-export')
            ->setDescription('Bulk load jobs into Marquez tool.')
            ->addArgument('marquezUrl', InputArgument::REQUIRED, 'Marquez tool API URL')
            ->addArgument('connectionUrl', InputArgument::OPTIONAL, 'Keboola Connection URL', 'https://connection.keboola.com')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Queue Jobs Limit', 100)
            ->addOption('--job-names-configurations', null, InputOption::VALUE_NONE, 'Represent jobs in Marquez with configuration names')
        ;
    }

    private function findQueueServiceUrl(string $storageToken, string $storageUrl): string
    {
        $client = new StorageApiClient([
            'token' => $storageToken,
            'url' => $storageUrl,
        ]);

        $index = $client->indexAction((new IndexOptions())->setExclude(['components']));
        $queueUrl = null;
        foreach ($index['services'] as $service) {
            if ($service['id'] === 'queue') {
                $queueUrl = $service['url'];
            }
        }

        if (!$queueUrl) {
            throw new Exception('Job Queue not found in the services list.');
        }

        return $queueUrl;
    }

    private function createQueueClient(string $storageToken, string $queueUrl): Client
    {
        return new Client([
            'base_uri' => $queueUrl,
            'headers' => [
                'X-StorageApi-Token' => $storageToken,
            ],
        ]);
    }

    private function createMarquezClient(string $marquezUrl): Client
    {
        return new Client([
            'base_uri' => $marquezUrl,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token = getenv('STORAGE_API_TOKEN');
        if (!$token) {
            throw new Exception('Environment variable "STORAGE_API_TOKEN" missing.');
        }

        $connectionUrl = $input->getArgument('connectionUrl');
        assert(is_string($connectionUrl));
        $marquezUrl = $input->getArgument('marquezUrl');
        assert(is_string($marquezUrl));

        $queueClient = $this->createQueueClient(
            $token,
            $this->findQueueServiceUrl($token, $connectionUrl)
        );

        $marquezClient = $this->createMarquezClient($marquezUrl);
        $jobsResponse = $queueClient->request('GET', '/jobs?' . http_build_query([
            'createdTimeFrom' => '-20 days',
            'sortOrder' => 'desc',
            'status' => 'success',
            'limit' => $input->getOption('limit'),
        ]));

        $jobsToExport = $this->decodeResponse($jobsResponse);
        $output->writeln(sprintf('There are %s jobs to export.', count($jobsToExport)));

        foreach (array_reverse($jobsToExport) as $job) {
            assert(is_array($job));
            $result = $job['result'] ?? null;
            if (!is_array($result) || !isset($result['input'])) {
                $jobId = $job['id'];
                assert(is_string($jobId) || is_int($jobId));
                $output->writeln(sprintf('Skipping older job "%s" without I/O in the result.', $jobId));
                continue;
            }

            $jobId = $job['id'];
            assert(is_string($jobId) || is_int($jobId));
            $output->writeln(sprintf('Job %s export to Marquez - start', $jobId));

            $lineAgeResponse = $queueClient->request('GET', sprintf('/jobs/%s/open-api-lineage', $jobId));
            foreach ($this->decodeResponse($lineAgeResponse) as $event) {
                assert(is_array($event));
                if ($input->getOption('job-names-configurations')) {
                    $jobComponent = $job['component'];
                    $jobConfig = $job['config'];
                    assert(is_string($jobComponent) || is_int($jobComponent));
                    assert(is_string($jobConfig) || is_int($jobConfig));
                    $eventJob = $event['job'];
                    assert(is_array($eventJob));
                    $eventJob['name'] = sprintf('%s-%s', $jobComponent, $jobConfig);
                    $event['job'] = $eventJob;
                }

                $eventType = $event['eventType'];
                assert(is_string($eventType));
                $output->writeln(sprintf('- Sending %s event', $eventType));
                $marquezClient->request('POST', '/api/v1/lineage', [
                    'body' => json_encode($event),
                ]);
            }

            $output->writeln(sprintf('Job %s export to Marquez - end', $jobId));
        }

        $output->writeln('Done');

        return 0;
    }

    /**
     * @return array<mixed, mixed>
     */
    private function decodeResponse(ResponseInterface $response): array
    {
        $decoded = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($decoded));
        return $decoded;
    }
}
