<?php

namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Keboola\ManageApi\ClientException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class InitDataRetention extends Command
{
    /**
     * @var OutputInterface
     */
    protected $_output;

    /**
     * Configure command, set parameters definition and help.
     */
    protected function configure()
    {
        $this
            ->setName('storage:set-data-retention')
            ->setDescription('Set Data Retention Time in Days for projects')
            ->addArgument('token', InputArgument::REQUIRED, 'manage api token')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'API URL', 'https://connection.keboola.com');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $client = new Client([
            'token' => $input->getArgument('token'),
            'url' => $input->getOption('url'),
        ]);

        $fh = fopen('php://stdin', 'r');
        if (!$fh) {
            throw new \Exception('Error on input read');
        }

        $lineNumber = 0;
        while ($row = fgetcsv($fh)) {
            if ($lineNumber === 0) {
                $this->validateHeader($row);
            } else {
                $projectId = (int) trim($row[0]);
                $dataRetentionTimeInDays = (int) trim($row[1]);
                try {
                    $client->updateProject($projectId, ['dataRetentionTimeInDays' => $dataRetentionTimeInDays]);
                    $output->writeln("Updated project " . $projectId . " to data retention period " . $dataRetentionTimeInDays);
                } catch (ClientException $e) {
                    $output->writeln("Error updating project " . $projectId . ".  " . $e->getMessage());
                }
            }
            $lineNumber++;
        }

        $output->writeln("All done.");
    }

    private function validateHeader($header)
    {
        $expectedHeader = ['projectId', 'dataRetentionTimeInDays'];
        if ($header !== $expectedHeader) {
            throw new \Exception(sprintf(
                'Invalid input header: %s Expected header: %s',
                implode(',', $header),
                implode(',', $expectedHeader)
            ));
        }
    }
}
