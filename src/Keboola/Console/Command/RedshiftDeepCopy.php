<?php
namespace Keboola\Console\Command;

use Keboola\Csv\CsvFile;
use Keboola\ManageApi\ClientException;
use Keboola\StorageApi\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RedshiftDeepCopy extends Command
{

    protected function configure()
    {
        $this
            ->setName('storage:redshift-deep-copy')
            ->setDescription('Creates a snapshot of a table and recreates the table from the snapshot')
            ->addArgument('token', InputArgument::REQUIRED, 'storage api token')
            ->addArgument('table', InputArgument::REQUIRED, 'table to deep copy')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Dry run (do not ovewrite original table)')
        ;
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $token = $input->getArgument('token');
        $tableId = $input->getArgument('table');
        $bucketId = substr($tableId, 0, strrpos($tableId, "."));
        $tableName = substr($tableId, strrpos($tableId, ".") + 1);

        $client = new Client(["token" => $token]);
        if (!$client->tableExists($tableId)) {
            throw new \Exception("Table {$tableId} does not exist");
        }

        $snapshotId = $client->createTableSnapshot($tableId, "Deep Copy");
        $client->createTableFromSnapshot($bucketId, $snapshotId, $tableName . "__stg");
        $tableInfo = $client->getTable($tableId);
        $newTableInfo = $client->getTable($tableId . "__stg");

        if ($tableInfo["rowsCount"] != $newTableInfo["rowsCount"]) {
            $client->dropTable($tableId . "__stg");
            throw new \Exception("Rows not equal!");
        }
        if ($tableInfo["dataSizeBytes"] <= 1.5 * $newTableInfo["dataSizeBytes"]) {
            $output->writeln("Deep copy of {$tableId} not required");
        } else {
            $output->writeln("Deep copy of {$tableId} required");
            if (!$input->getOption("dry-run")) {
                // magic!
                try {
                    $client->dropTable($tableId);
                    $client->createTableFromSnapshot($bucketId, $snapshotId, $tableName);
                    $output->writeln("Deep copy done");
                } catch (\Keboola\StorageApi\ClientException $e) {
                    $client->dropTable($tableId . "__stg");
                    throw $e;
                }
            }
        }
        $client->dropTable($tableId . "__stg");
    }
}
