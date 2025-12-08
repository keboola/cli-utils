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
    protected function configure(): void
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manageToken = $input->getArgument('manageToken');
        assert(is_string($manageToken));
        $organizationId = $input->getArgument('organizationId');
        assert(is_string($organizationId));
        $organizationId = is_numeric($organizationId) ? (int) $organizationId : (int) $organizationId;
        $organizationId = (int) $organizationId;
        $userEmail = $input->getArgument('userEmail');
        assert(is_string($userEmail));
        $hostnameSuffix = $input->getArgument('hostnameSuffix');
        assert(is_string($hostnameSuffix));
        $kbcUrl = sprintf('https://connection.%s', $hostnameSuffix);

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
                'Checking "%d" projects for user %s',
                count($projects),
                $userEmail
            )
        );
        $affectedProjects = 0;
        foreach ($projects as $project) {
            $projectUsers = $manageClient->listProjectUsers((int) $project['id']);
            if ($this->isUserInProject((int) $user['id'], $projectUsers)) {
                $output->writeln(sprintf(
                    'Removing user "%s" from project "%d":"%s"',
                    $userEmail,
                    $project['id'],
                    $project['name']
                ));
                if ($force) {
                    $manageClient->removeUserFromProject((int) $project['id'], $user['id']);
                }
                $affectedProjects++;
            }
        }
        $output->writeln(
            sprintf(
                'Finished. %s has been removed from %d projects.',
                $userEmail,
                $affectedProjects
            )
        );

        return 0;
    }

    /**
     * @param array<int, array<string, mixed>> $projectUsers
     */
    private function isUserInProject(int $userId, array $projectUsers): bool
    {
        foreach ($projectUsers as $projectUser) {
            if ((int) $projectUser['id'] === $userId) {
                return true;
            }
        }
        return false;
    }
}
