<?php

namespace Keboola\Console\Command;

use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TouchTables extends Command
{

    const TOUCH_ATTRIBUTE_NAME = 'keboola.touch';

    protected function configure()
    {
        $this
            ->setName('storage:touch-tables')
            ->setDescription('Set keboola.touch attribute to all tables. This will invalidate async export caches.')
            ->addArgument('token', InputArgument::REQUIRED, 'storage api token');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $token = $input->getArgument('token');
        $client = new Client(["token" => $token]);

        $currentTimestamp = time();
        $output->writeln(sprintf(
            'Setting attribute %s to all tables with value %s',
            self::TOUCH_ATTRIBUTE_NAME,
            $currentTimestamp
        ));

        foreach ($client->listTables() as $table) {
            $output->writeln($table['id']);
            try {
                $client->setTableAttribute($table['id'], self::TOUCH_ATTRIBUTE_NAME, $currentTimestamp);
            } catch (ClientException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            }
            try {
                $client->deleteTableAttribute($table['id'], self::TOUCH_ATTRIBUTE_NAME);
            } catch (ClientException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            }
        }
    }
}
