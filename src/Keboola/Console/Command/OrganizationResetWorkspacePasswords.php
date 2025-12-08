<?php

namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationResetWorkspacePasswords extends Command
{
    const ARGUMENT_MANAGE_TOKEN = 'manageToken';
    const ARGUMENT_ORGANIZATION_ID = 'organizationId';
    const ARGUMENT_HOSTNAME_SUFFIX = 'hostnameSuffix';
    const ARGUMENT_SNOWFLAKE_HOSTNAME = 'snowflakeHostname';
    const OPTION_FORCE = 'force';

    protected function configure(): void
    {
        $this
            ->setName('manage:reset-organization-workspace-passwords')
            ->setDescription('Reset workspace passwords for all projects in an organization from sandboxes service -> Connection')
            ->addOption(
                self::OPTION_FORCE,
                'f',
                InputOption::VALUE_NONE,
                'Use [--force, -f] to do it for real.'
            )
            ->addArgument(self::ARGUMENT_MANAGE_TOKEN, InputArgument::REQUIRED, 'Maname Api Token')
            ->addArgument(self::ARGUMENT_ORGANIZATION_ID, InputArgument::REQUIRED, 'Organization Id')
            ->addArgument(self::ARGUMENT_SNOWFLAKE_HOSTNAME, InputArgument::REQUIRED, 'Hostname of the target Snowflake account. Including https')
            ->addArgument(
                self::ARGUMENT_HOSTNAME_SUFFIX,
                InputArgument::OPTIONAL,
                'Keboola Connection Hostname Suffix',
                'keboola.com'
            )
        ;
    }


    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manageToken = $input->getArgument(self::ARGUMENT_MANAGE_TOKEN);
        $snowflakeHostname = $input->getArgument(self::ARGUMENT_SNOWFLAKE_HOSTNAME);
        $organizationId = $input->getArgument(self::ARGUMENT_ORGANIZATION_ID);
        $kbcUrl = sprintf('https://connection.%s', $input->getArgument(self::ARGUMENT_HOSTNAME_SUFFIX));

        $manageClient = new Client(['token' => $manageToken, 'url' => $kbcUrl]);

        $organization = $manageClient->getOrganization($organizationId);
        $projects = $organization['projects'];

        $output->writeln(
            sprintf(
                'Will reset passwords for "%d" projects',
                count($projects),
            )
        );
        $force = $input->getOption(self::OPTION_FORCE);
        $output->writeln($force ? 'FORCE MODE' : 'DRY RUN');
        foreach ($projects as $project) {
            $output->writeln(
                sprintf(
                    'Reseting workspace passwords for project %s',
                    $project['id'],
                )
            );
            $params = [(string) $project['id'], $snowflakeHostname];
            if ($force) {
                $params[] = '--force';
                $response = $manageClient->runCommand([
                    'command' => 'manage:storage-backend:byodb:reset-snowflake-sandboxes-password',
                    'parameters' => $params
                ]);
                $output->writeln(sprintf('Password reset for project "%s" in progress using command "%s".', $project['id'], $response['commandExecutionId']));
            }
        }
        $output->writeln('All done.');

        return 0;
    }
}
