<?php

namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationStorageBackend extends Command
{
    const ARGUMENT_MANAGE_TOKEN = 'manageToken';
    const ARGUMENT_ORGANIZATION_ID = 'organizationId';
    const ARGUMENT_STORAGE_BACKEND_ID = 'storageBackendId';
    const ARGUMENT_HOSTNAME_SUFFIX = 'hostnameSuffix';
    const OPTION_FORCE = 'force';
    protected function configure(): void
    {
        $this
            ->setName('manage:set-organization-storage-backend')
            ->setDescription('Set the storage backend for all projects in an organization')
            ->addOption(
                self::OPTION_FORCE,
                'f',
                InputOption::VALUE_NONE,
                'Use [--force, -f] to do it for real.'
            )
            ->addArgument(self::ARGUMENT_MANAGE_TOKEN, InputArgument::REQUIRED, 'Maname Api Token')
            ->addArgument(self::ARGUMENT_ORGANIZATION_ID, InputArgument::REQUIRED, 'Organization Id')
            ->addArgument(
                self::ARGUMENT_STORAGE_BACKEND_ID,
                InputArgument::REQUIRED,
                'The ID of the storage backend'
            )
            ->addArgument(
                self::ARGUMENT_HOSTNAME_SUFFIX,
                InputArgument::OPTIONAL,
                'Keboola Connection Hostname Suffix',
                'keboola.com'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storageBackendId = $input->getArgument(self::ARGUMENT_STORAGE_BACKEND_ID);
        assert(is_string($storageBackendId));
        $manageToken = $input->getArgument(self::ARGUMENT_MANAGE_TOKEN);
        assert(is_string($manageToken));
        $organizationId = $input->getArgument(self::ARGUMENT_ORGANIZATION_ID);
        assert(is_string($organizationId));
        $organizationId = is_numeric($organizationId) ? (int) $organizationId : (int) $organizationId;
        $organizationId = (int) $organizationId;
        $hostnameSuffix = $input->getArgument(self::ARGUMENT_HOSTNAME_SUFFIX);
        assert(is_string($hostnameSuffix));
        $kbcUrl = sprintf('https://connection.%s', $hostnameSuffix);

        $manageClient = new Client(['token' => $manageToken, 'url' => $kbcUrl]);

        $organization = $manageClient->getOrganization($organizationId);
        $projects = $organization['projects'];

        $output->writeln(
            sprintf(
                'Will set "%d" projects to use storage backend "%s"',
                count($projects),
                $storageBackendId
            )
        );
        $force = (bool) $input->getOption(self::OPTION_FORCE);
        $output->writeln($force ? 'FORCE MODE' : 'DRY RUN');
        foreach ($projects as $project) {
            $output->writeln(
                sprintf(
                    'Setting project %s to use storage ID %s',
                    $project['id'],
                    $storageBackendId
                )
            );
            $params = [(string) $project['id'], (string) $storageBackendId];
            if ($force) {
                $params[] = '--force';
                $result = $manageClient->runCommand([
                    'command' => 'manage:switch-storage-backend',
                    'parameters' => $params,
                ]);
                $output->writeln(sprintf('INFO: Storage backend switch for project "%s" in progress using command "%s".', $project['id'], $result['commandExecutionId']));

                if ($this->waitForProjectToMigrate($manageClient, $project['id'], (int) $storageBackendId, $output)) {
                    $output->writeln(sprintf('SUSCCESS: Storage backend switch for project "%s" in progress using command "%s".', $project['id'], $result['commandExecutionId']));
                    // wait a second so the lock can be released
                    sleep(5);
                } else {
                    $output->writeln(sprintf('ERROR: Storage backend switch for project "%s" to backend "%s" timed out.', $project['id'], $storageBackendId));
                }
            }
        }
        $output->writeln('All done.');

        return 0;
    }

    private function waitForProjectToMigrate(
        Client $manageClient,
        int $projectId,
        int $requestedProjectId,
        OutputInterface $output,
    ): bool {
        $timeout = 300;
        $start = time();
        while (time() - $start < $timeout) {
            $projectDetail = $manageClient->getProject($projectId);
            $currentBackednId = (int) $projectDetail['backends']['snowflake']['id'];

            if ($currentBackednId === $requestedProjectId) {
                $output->writeln(sprintf(' - Project "%s" migrated successfully to backend "%s".', $projectId, $requestedProjectId));
                return true;
            }
            $output->writeln(sprintf(' - Project "%s" did not migrate to backend "%s" yet...waiting', $projectId, $requestedProjectId));
            sleep(2);
        }

        return false;
    }
}
