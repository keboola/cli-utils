<?php
namespace Keboola\Console\Command;

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

    protected function configure()
    {
        $this
            ->setName('storage:lineage-events-export')
            ->setDescription('Bulk load jobs into Marquez tool.')
            ->addArgument('token', InputArgument::REQUIRED, 'Storage API token')
            ->addArgument('marquezUrl', InputArgument::REQUIRED, 'Marquez tool URL')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'KBC URL', 'https://connection.keboola.com')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $token = $input->getArgument('token');

        $client = new StorageApiClient([
            'token' => $token,
            'url' => $input->getOption('url'),
        ]);


        $index = $client->indexAction((new IndexOptions())->setExclude(['components']));

        $queueUrl = null;
        foreach($index['services'] as $service) {
            if ($service['id'] === 'queue') {
                $queueUrl = $service['url'];
            }
        }

        if (!$queueUrl) {
            throw new \Exception('Job Queue not found in services list.');
        }

        $requestOptions = [
            'headers' => [
                'X-StorageApi-Token' => $token,
            ],
        ];

        $queueGuzzle = new Client([
            'base_uri' => $queueUrl,
        ]);

        $marquezGuzzle = new Client([
            'base_uri' => $input->getArgument('marquezUrl'),
        ]);

        $response = $queueGuzzle->request('GET', '/jobs?createdTimeFrom=-20%20days', $requestOptions);

        foreach ($this->decodeResponse($response) as $job) {
            if (!isset($job['result']['input'])) {
                $output->writeln(sprintf('Skipping older job %s without I/O in the result.', $job['id']));
                continue;
            }

            $output->writeln(sprintf('Send job %s dat to Marquez - start', $job['id']));

            $response = $queueGuzzle->request('GET', sprintf('/jobs/%s/open-api-lineage', $job['id']), $requestOptions);
            foreach ($this->decodeResponse($response) as $event) {
                $output->writeln(sprintf('Sending %s event', $event['eventType']));
                $marquezGuzzle->request('POST', '/api/v1/lineage', [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode($event),
                ]);
            }

            $output->writeln(sprintf('Send job %s dat to Marquez - end', $job['id']));
        }

        $output->writeln('Done');
    }

    private function decodeResponse(ResponseInterface $response)
    {
        return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
    }
}
