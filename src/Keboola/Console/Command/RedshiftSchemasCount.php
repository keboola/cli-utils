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

class RedshiftSchemasCount extends Command
{

    protected function configure()
    {
        $this
            ->setName('storage:redshift-schemas-count')
            ->setDescription('Checks for the count of Redshift schemas in each project\'s database')
            ->addArgument('token', InputArgument::REQUIRED, 'manage api token')
            ->addArgument('projects', InputArgument::REQUIRED, 'single project id or range (eg 10..500)')
        ;
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $token = $input->getArgument('token');

        if (strpos($input->getArgument('projects'), '..')) {
            list($start, $end) = explode('..', $input->getArgument('projects'));
            $projects = range($start, $end);
        } else {
            $projects = [$input->getArgument('projects')];
        }

        $manageClient = new \Keboola\ManageApi\Client(["token" => $token]);
        $manageClient->verifyToken();

        $backends = $manageClient->listStorageBackend(["logins" => true]);
        // var_dump($backends);


        foreach ($projects as $projectId) {
            try {
                $projectInfo = $manageClient->getProject($projectId);
            } catch (ClientException $e) {
                $output->writeln($e->getMessage());
                continue;
            }

            // Disabled projects
            if (isset($projectInfo["isDisabled"]) && $projectInfo["isDisabled"]) {
                $output->writeln("Project {$projectId} disabled.");
                continue;
            }
            if (!isset($projectInfo["hasRedshift"]) || $projectInfo["hasRedshift"] == false) {
                $output->writeln("Project {$projectId} does not have Redshift.");
                continue;
            }
            // login and count schemas
            $backendConnection = null;
            foreach($backends as $backend) {
                if ($backend["id"] == $projectInfo["backends"]["redshift"]["id"]) {
                    $backendConnection = $backend;
                }
            }
            if (!$backendConnection) {
                $output->writeln('Backend connection for project ' . $projectId . ' not found');
            }
            try {
                $dsn = 'pgsql:host=' . $backendConnection["host"] . ';port=5439;dbname=sapi_' . $projectId;
                $pdo = new \PDO($dsn, explode("/", $backendConnection["login"])[0], explode("/", $backendConnection["login"])[1]);
                $result = $pdo->query("select COUNT(*) from pg_namespace;");
                $count = $result->fetchColumn(0);
                $result->closeCursor();
                if ($count >= 255) {
                    $output->writeln("<fg=red>Project {$projectId} has {$count} schemas out of 256.</fg=red>");
                } else if ($count >= 200) {
                    $output->writeln("<fg=yellow>Project {$projectId} has {$count} schemas out of 256.</fg=yellow>");
                } else {
                    $output->writeln("<fg=green>Project {$projectId} has {$count} schemas out of 256.</fg=green>");
                }
                $pdo = null;
            } catch (\Exception $e) {
                $output->writeln($e->getMessage());
                $output->write("\n");
            }
        }
    }
}
