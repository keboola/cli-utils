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
            ->addArgument('projectId', InputArgument::REQUIRED, 'project id to migrate')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'KBC URL', 'https://connection.keboola.com')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $token = $input->getArgument('token');
        $projectId = (int) $input->getArgument('projectId');

        $client = new Client([
            'token' => $token,
            'url' => $input->getOption('url'),
        ]);

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
}
