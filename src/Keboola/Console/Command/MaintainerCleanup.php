<?php
namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MaintainerCleanup extends Command
{

    protected function configure()
    {
        $this
            ->setName('storage:maintainer-cleanup')
            ->setDescription('Purge maintainer.')
            ->addArgument('token', InputArgument::REQUIRED, 'manage api token')
            ->addArgument('url', InputArgument::REQUIRED, 'KBC URL')
            ->addArgument('maintainer-id', InputArgument::REQUIRED, 'id of maintainer to purge')
            ->addArgument('skip-organizations', InputArgument::OPTIONAL, 'comma separated list of organization ids to skip')
            ->addOption('dry-run', null, InputOption::VALUE_NONE)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dryRun = $input->getOption('dry-run');
        if ($dryRun) {
            $output->writeln('Running in Dry Run mode');
        }

        $skipOrganizationIds = $input->getArgument('skip-organizations') ? explode(',', $input->getArgument('skip-organizations')) : [];
        $client = new Client([
            'token' =>  $input->getArgument('token'),
            'url' => $input->getArgument('url'),
        ]);

        foreach ($client->listMaintainerOrganizations($input->getArgument('maintainer-id')) as $organization) {
            if (in_array($organization['id'], $skipOrganizationIds)) {
                $output->writeln(sprintf('SKIPPING organization %s (%d)', $organization['name'], $organization['id']));
                continue;
            }
            $this->purgeOrganization($output, $client, $dryRun, $organization);
        }
    }

    private function purgeOrganization(OutputInterface $output, Client $client, $dryRun, array $organization)
    {
        $output->writeln(sprintf('Purging organization %s (%d)', $organization['name'], $organization['id']));
        foreach ($client->listOrganizationProjects($organization['id']) as $project) {
            $this->purgeProject($output, $client, $dryRun, $project);
        }
        if (!$dryRun) {
            $client->deleteOrganization($organization['id']);
        }
    }

    private function purgeProject(OutputInterface $output, Client $client, $dryDrun, array $project)
    {
        $output->writeln(sprintf(' - Deleting project %s (%d)', $project['name'], $project['id']));

        if (!$dryDrun) {
            $client->deleteProject($project['id']);
            $client->purgeDeletedProject($project['id']);
        }
    }
}
