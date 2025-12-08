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
            if (!isset($job['result']['input'])) {
                $output->writeln(sprintf('Skipping older job "%s" without I/O in the result.', $job['id']));
                continue;
            }

            $output->writeln(sprintf('Job %s export to Marquez - start', $job['id']));

            $lineAgeResponse = $queueClient->request('GET', sprintf('/jobs/%s/open-api-lineage', $job['id']));
            foreach ($this->decodeResponse($lineAgeResponse) as $event) {
                if ($input->getOption('job-names-configurations')) {
                    $event['job']['name'] = sprintf('%s-%s', $job['component'], $job['config']);
                }

                $output->writeln(sprintf('- Sending %s event', $event['eventType']));
                $marquezClient->request('POST', '/api/v1/lineage', [
                    'body' => json_encode($event),
                ]);
            }

            $output->writeln(sprintf('Job %s export to Marquez - end', $job['id']));
        }

        $output->writeln('Done');

        return 0;
    }

    /**
     * @return array<int, mixed>
     */
    private function decodeResponse(ResponseInterface $response): array
    {
        return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
    }
}
