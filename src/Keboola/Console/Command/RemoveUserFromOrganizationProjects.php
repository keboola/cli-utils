<?php

namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveUserFromOrganizationProjects extends Command
{
    protected function configure()
    {
        $this
            ->setName('manage:remove-user-from-organization-projects')
            ->setDescription('Remove the provided user from all projects in this organization.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Use [--force, -f] to do it for real.')
            ->addArgument(
                'manageToken',
                InputArgument::REQUIRED,
                'Keboola Storage API token to use'
            )
            ->addArgument(
                'organizationId',
                InputArgument::REQUIRED,
                'ID of the organization to clean'
            )
            ->addArgument(
                'userEmail',
                InputArgument::REQUIRED,
                'Email of the user to remove from all projects in the organization.'
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
        $manageToken = $input->getArgument('manageToken');
        $organizationId = $input->getArgument('organizationId');
        $userEmail = $input->getArgument('userEmail');
        $kbcUrl = sprintf('https://connection.%s', $input->getArgument('hostnameSuffix'));

        $manageClient = new Client(['token' => $manageToken, 'url' => $kbcUrl]);

        $organization = $manageClient->getOrganization($organizationId);

        $user = $manageClient->getUser($userEmail);

        $force = (bool) $input->getOption('force');
        if ($force) {
            $output->writeln('Force option is set, doing it for real');
        } else {
            $output->writeln('This is just a dry-run, nothing will be actually deleted');
        }

        $projects = $organization['projects'];
        $output->writeln(
            sprintf(
                'Removing %s from "%d" projects',
                $userEmail,
                count($projects)
            )
        );
        foreach ($projects as $project) {
            $output->writeln(sprintf(
                'Removing user "%s" from project "%d":"%s"',
                $userEmail,
                $project['id'],
                $project['name']
            ));
            if ($force) {
                $manageClient->removeUserFromProject($project['id'], $user['id']);
            }
        }
    }
}
