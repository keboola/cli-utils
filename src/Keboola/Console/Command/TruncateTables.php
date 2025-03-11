<?php

namespace Keboola\Console\Command;

use Keboola\StorageApi\Client as StorageApiClient;
use Keboola\StorageApi\Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TruncateTables extends Command
{
    protected function configure()
    {
        $this
            ->setName('storage:truncate-tables')
            ->setDescription('Bulk truncate project tables.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Use [--force, -f] to do it for real.')
            ->addArgument(
                'storageToken',
                InputArgument::REQUIRED,
                'Keboola Storage API token to use'
            )
            ->addArgument(
                'hostnameSuffix',
                InputArgument::OPTIONAL,
                'Keboola Connection Hostname Suffix',
                'keboola.com'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $token = $input->getArgument('storageToken');
        $url = 'https://connection.' . $input->getArgument('hostnameSuffix');
        $force = (bool) $input->getOption('force');

        $storageClient = new StorageApiClient([
            'token' => $token,
            'url' => $url,
        ]);
        if ($force) {
            $output->writeln('Force option is set, doing it for real');
        } else {
            $output->writeln('This is just a dry-run, nothing will be actually deleted');
        }
        $totalTruncatedTables = 0;

        $tables = $storageClient->listTables();
        $totalTables = count($tables);

        foreach ($tables as $table) {
            $output->writeln('Truncating table ' . $table['id']);
            if ($table['isAlias']) {
                $output->writeln('Skipping table ' . $table['id'] . ' because it is an alias');
                continue;
            }
            if ($table['rowsCount'] === 0) {
                $output->writeln('Skipping table ' . $table['id'] . ' because it is already empty');
                continue;
            }
            if ($force) {
                try {
                    $storageClient->apiDeleteParamsJson("tables/{$table['id']}/rows", ['allowTruncate' => true]);
                    $totalTruncatedTables++;
                } catch (\Throwable $exception) {
                    if ($exception->getCode() === 404) {
                        $output->writeln(sprintf("Storage table %s not found", $table['id']));
                    } else {
                        throw $exception;
                    }
                }
            }
        }

        $output->writeln(sprintf(
            'Truncated %d tables of total %d',
            $totalTruncatedTables,
            $totalTables
        ));
    }
}
