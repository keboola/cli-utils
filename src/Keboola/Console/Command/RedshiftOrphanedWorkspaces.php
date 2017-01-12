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

/*
    SELECT
     id, workspaceId, tokenOwnerId
    FROM RedshiftWorkspaceAccount
    WHERE
     active = 0;
 */

class RedshiftOrphanedWorkspaces extends Command
{



    protected function configure()
    {
        $this
            ->setName('storage:redshift-orphaned-workspaces')
            ->setDescription('Checks for orphaned Redshift workspaces in each project\'s database')
            ->addArgument('token', InputArgument::REQUIRED, 'manage api token')
            ->addArgument('projects', InputArgument::REQUIRED, 'single project id or range (eg 10..500)')
            ->addArgument('source-file', InputArgument::REQUIRED, 'source file with deactivated workspaces, columns "id, workspaceId, tokenOwnerId"')
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

        $csvFile = new CsvFile($input->getArgument('source-file'));

        if ($csvFile->getHeader()[0] != 'id') {
            throw new \Exception('Column "id" not defined in file.');
        }
        if ($csvFile->getHeader()[1] != 'workspaceId') {
            throw new \Exception('Column "workspaceId" not defined in file.');
        }
        if ($csvFile->getHeader()[2] != 'tokenOwnerId') {
            throw new \Exception('Column "tokenOwnerId" not defined in file.');
        }

        $orphans = [];
        $csvFile->rewind();
        // skip header
        $csvFile->next();
        while ($csvFile->current()) {
            $orphans[] = 'workspace_' . $csvFile->current()[1];
            $csvFile->next();
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
            foreach ($backends as $backend) {
                if ($backend["id"] == $projectInfo["backends"]["redshift"]["id"]) {
                    $backendConnection = $backend;
                }
            }
            if (!$backendConnection) {
                $output->writeln('Backend connection for project ' . $projectId . ' not found');
                continue;
            }

            $output->writeln("Detecting orphaned workspaces for project {$projectId}.");

            try {
                $dsn = 'pgsql:host=' . $backendConnection["host"] . ';port=5439;dbname=sapi_' . $projectId;
                $pdo = new \PDO($dsn, explode("/", $backendConnection["login"])[0], explode("/", $backendConnection["login"])[1]);
                $result = $pdo->query("select nspname from pg_namespace where nspname like 'workspace_%';");

                foreach ($result->fetchAll() as $row) {
                    if (in_array($row["nspname"], $orphans)) {
                        $output->writeln("<fg=red>Project {$projectId} has orphaned schema {$row["nspname"]}.</fg=red>");
                    }
                }
                $result->closeCursor();
                $pdo = null;
            } catch (\Exception $e) {
                $output->writeln($e->getMessage());
                $output->write("\n");
            }
        }
    }
}
