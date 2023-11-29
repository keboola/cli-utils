<?php

namespace Keboola\Console\Command;

use GuzzleHttp\Client as GuzzleClient;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReactivateSchedules extends Command
{
    /**
     * @var OutputInterface
     */
    protected $_output;

    private string $stackSuffix;

    /**
     * Configure command, set parameters definition and help.
     */
    protected function configure()
    {
        $this
            ->setName('storage:reactivate-schedules')
            ->setDescription('Reactivate schedules after SOX migration')
            ->addArgument('token', InputArgument::REQUIRED, 'SAPI token of PM')
            ->addArgument('stack', null, InputOption::VALUE_REQUIRED, 'stack suffix');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->stackSuffix = $input->getArgument('stack');
        $token = $input->getArgument('token');

        $connectionUrl = $this->buildUrl('connection');
        $schedulerUrl = $this->buildUrl('scheduler');

        $components = new Components(new StorageClient([
            'url' => $connectionUrl,
            'token' => $token,
        ]));

        $guzzleClient = new GuzzleClient([
            'base_uri' => $schedulerUrl,
            'headers' => [
                'X-StorageApi-Token' => $token,
            ],
        ]);

        // list configurations
        $configurations = $components->listComponentConfigurations(
            (new ListComponentConfigurationsOptions())
                ->setComponentId('keboola.scheduler')
                ->setIsDeleted(false)
        );

// for each configuration
// - DELETE https://scheduler.keboola.com/configurations/<ID>
// - POST https://scheduler.keboola.com/schedules s { "configurationId": "<ID>" }

        foreach ($configurations as $configuration) {
            $output->writeln("deleting configuration {$configuration['id']}");
            $response = $guzzleClient->delete('/configurations/' . $configuration['id']);

            $output->writeln("activating schedule for configuration {$configuration['id']}");
            $response = $guzzleClient->post('/configurations/', ['configuration' => $configuration['id']]);
        }
    }

    private function buildUrl(string $serviceKey): string
    {
        return sprintf(
            'https://%s.%s',
            $serviceKey,
            $this->stackSuffix,
        );
    }
}
