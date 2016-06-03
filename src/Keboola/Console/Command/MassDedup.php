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

class MassDedup extends Command
{

    protected function configure()
    {
        $this
            ->setName('storage:mass-dedup')
            ->setDescription('Migrates configuration from Storage API tables to configurations')
            ->addArgument('token', InputArgument::REQUIRED, 'manage token')
            ->addArgument('source-file', InputArgument::REQUIRED, 'source file')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Dry run (do not save data)')
        ;
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $token = $input->getArgument('token');
        $csvFile = new CsvFile($input->getArgument('source-file'));
        $projects = [];

        if ($csvFile->getHeader()[0] != 'project') {
            throw new \Exception('Project not defined in file.');
        }
        if ($csvFile->getHeader()[1] != 'table') {
            throw new \Exception('Table not defined in file.');
        }

        $csvFile->rewind();
        $csvFile->next();

        while($csvFile->valid()) {
            $row = $csvFile->current();
            $projects[] = $row[0];
            $csvFile->next();
        }

        $projects = array_unique($projects);

        $manageClient = new \Keboola\ManageApi\Client(["token" => $token]);
        $manageClient->verifyToken();

        foreach($projects as $projectId) {
            $output->writeln("");
            $output->writeln("Processing project " . $projectId);
            try {
                $projectInfo = $manageClient->getProject($projectId);
            } catch (ClientException $e) {
                $output->writeln($e->getMessage());
                $output->write("\n");
                continue;
            }

            // Disabled projects
            if (isset($projectInfo["isDisabled"]) && $projectInfo["isDisabled"]) {
                $output->writeln("Project disabled: " . $projectInfo["disabled"]["reason"]);
            } else {

                $projectToken = $manageClient->createProjectStorageToken(
                    $projectId,
                    [
                        "description" => "Dedup tables",
                        "canManageBuckets" => true,
                        "canReadAllFileUploads" => true,
                        "expiresIn" => 3600
                    ]
                );

                $client = new Client(["token" => $projectToken["token"]]);

                $csvFile->rewind();
                $csvFile->next();

                while($csvFile->valid()) {

                    $row = $csvFile->current();
                    $projects[] = $row[0];

                    if ($row[0] == $projectId) {
                        if (!$client->tableExists($row[1])) {
                            $output->writeln("Table " . $row[1] . " does not exist");
                            $csvFile->next();
                            continue;
                        }
                        try {
                            $output->writeln("Processing table " . $row[1]);
                            // detect dedup
                            $output->write("Dedup... ");
                            // TODO
                            if (false) {
                                $output->writeln("not required");
                                $csvFile->next();
                                continue;
                            } else {
                                $output->writeln("required");
                            }

                            if (!$input->getOption("dry-run")) {
                                // snapshot
                                $output->write("Snapshot... ");
                                $client->createTableSnapshot($row[1], "Backup before deduplication");
                                $output->writeln("created");

                                $output->write("Dedup job... ");
                                //$this->dedupTable($client, $row['table'], $input, $output);
                                $output->writeln("created");
                            }
                        } catch (\Exception $e) {
                            print $e->getTraceAsString();
                            throw $e;
                        }
                    }
                    $csvFile->next();
                }
            }
            $output->write("\n");
        }
    }
}
