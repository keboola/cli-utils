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
    const OPT_FORCE = 'force';
    const ARG_TOKEN = 'token';
    const ARG_STACK = 'stack';

    private string $stackSuffix;

    /**
     * Configure command, set parameters definition and help.
     */
    protected function configure()
    {
        $this
            ->setName('storage:reactivate-schedules')
            ->setDescription('Reactivate schedules after SOX migration')
            ->addArgument(self::ARG_TOKEN, InputArgument::REQUIRED, 'SAPI token of PM')
            ->addArgument(self::ARG_STACK, InputArgument::OPTIONAL, 'stack suffix', 'keboola.com')
            ->addOption(self::OPT_FORCE, 'f', InputOption::VALUE_NONE, 'Use [--force, -f] to do it for real.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $isForce = $input->getOption(self::OPT_FORCE);

        $prefix = $isForce ? 'FORCE: ' : 'DRY-RUN: ';
        $output->writeln('Running ' . ($isForce ? 'force mode' : 'in dry run mode'));

        $this->stackSuffix = $input->getArgument(self::ARG_STACK);
        $token = $input->getArgument(self::ARG_TOKEN);

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
                'Content-Type' => 'application/json',
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
        // - POST https://scheduler.keboola.com/schedules with { "configurationId": "<ID>" }
        foreach ($configurations as $configuration) {
            $output->writeln($prefix . 'Deleting configuration ' . $configuration['id']);
            if ($isForce) {
                $guzzleClient->delete('/configurations/' . $configuration['id']);
            }

            $output->writeln($prefix . 'Activating schedule for configuration ' . $configuration['id']);
            if ($isForce) {
                $guzzleClient->post('/schedules', ['body' => json_encode(['configurationId' => $configuration['id']])]);
            }
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
