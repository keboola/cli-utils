<?php
namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateFiles extends Command
{

    protected function configure()
    {
        $this
            ->setName('storage:migrate-files')
            ->setDescription('Migrate files metadata storage.')
            ->addArgument('token', InputArgument::REQUIRED, 'manage api token')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'KBC URL', 'https://connection.keboola.com')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $token = $input->getArgument('token');

        $fh = fopen('php://stdin', 'r');

        $client = new Client([
            'token' => $token,
            'url' => $input->getOption('url'),
        ]);

        $lineNumber = 0;
        while ($row = fgetcsv($fh)) {
            if ($lineNumber === 0) {
                $this->validateHeader($row);
            } else {
                $this->migrateProject($client, $output, (int) $row[0]);
            }
            $lineNumber++;
        }
    }

    private function validateHeader($header)
    {
        $expectedHeader = ['id'];
        if ($header !== $expectedHeader) {
            throw new \Exception(sprintf(
                'Invalid input header: %s Expected header: %s',
                implode(',', $header),
                implode(',', $expectedHeader)
            ));
        }
    }

    private function migrateProject(Client $client, OutputInterface $output, int $projectId)
    {
        $output->writeln(sprintf('Migrate project %d - start', $projectId));

        $client->runCommand([
           'command' => 'storage:project-files-migrate',
            'parameters' => [
                (string) $projectId,
            ],
        ]);
        $this->waitUntilMigrationIsDone($client, $projectId);

        $output->writeln(sprintf('Migrate project %d - end', $projectId));
    }

    private function waitUntilMigrationIsDone(Client $client, int $projectId)
    {
        while (1) {
            $project = $client->getProject($projectId);
            if (!in_array('files-legacy-elastic', $project['features'])) {
                return;
            }
            sleep(5);
        }
    }

    private function purgeProject(Client $client, OutputInterface $output, $ignoreBackendErrors, $projectId, $projectName)
    {
        $output->writeln(sprintf('Purge %s (%d)', $projectName, $projectId));

        $response = $client->purgeDeletedProject($projectId, [
            'ignoreBackendErrors' => (bool) $ignoreBackendErrors,
        ]);
        $output->writeln(" - execution id {$response['commandExecutionId']}");

        $startTime = time();
        $maxWaitTimeSeconds = 600;
        do {
            $deletedProject = $client->getDeletedProject($projectId);
            if (time() - $startTime > $maxWaitTimeSeconds) {
                throw new \Exception("Project {$projectId} purge timeout.");
            }
            sleep(1);
        } while ($deletedProject['isPurged'] !== true);

        $output->writeln(sprintf('Purge done %s (%d)', $projectName, $projectId));
    }
}
