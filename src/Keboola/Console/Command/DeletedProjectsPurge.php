<?php

namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Keboola\ManageApi\ClientException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DeletedProjectsPurge extends Command
{
    protected function configure()
    {
        $this
            ->setName('storage:deleted-projects-purge')
            ->setDescription('Purge deleted projects.')
            ->addArgument('url', InputArgument::REQUIRED, 'URL of stack including https://')
            ->addArgument('token', InputArgument::REQUIRED, 'manage api token')
            ->addArgument('projectIds', InputArgument::REQUIRED, 'IDs of projects to purge (separate multiple IDs with a space)')
            ->addOption('ignore-backend-errors', null, InputOption::VALUE_NONE, "Ignore errors from backend and just delete buckets and workspaces metadata")
            ->addOption('force', null, InputOption::VALUE_NONE, 'Actually perform destructive operations (purge). Without this flag, the command will only simulate actions.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $url = $input->getArgument('url');
        $token = $input->getArgument('token');
        $projectIds = $input->getArgument('projectIds');
        $ignoreBackendErrors = (bool) $input->getOption('ignore-backend-errors');
        $force = (bool) $input->getOption('force');

        $output->writeln(sprintf(
            'Ignore backend errors %s',
            $ignoreBackendErrors ? 'On' : 'Off'
        ));
        $output->writeln(sprintf(
            'Force mode %s',
            $force ? 'On (destructive operations will be performed)' : 'Off (no destructive operations will be performed)'
        ));

        $client = new Client([
            'url' => $url,
            'token' => $token,
        ]);
        $projectIds = array_filter(explode(',', $projectIds), 'is_numeric');

        foreach ($projectIds as $projectId) {
            $this->purgeProject(
                $client,
                $output,
                $ignoreBackendErrors,
                (int) $projectId,
                $force,
            );
        }
    }

    private function purgeProject(
        Client $client,
        OutputInterface $output,
        bool $ignoreBackendErrors,
        int $projectId,
        bool $force,
    ): void {
        try {
            $deletedProject = $client->getDeletedProject($projectId);
            if ($deletedProject['isPurged'] === true) {
                $output->writeln(sprintf('<info>INFO</info> Project "%d" purged already.', $projectId));
                return;
            }
        } catch (ClientException $e) {
            if ($e->getCode() === 404) {
                $output->writeln(sprintf('<error>Error</error>: Purge of the project "%d" not found.', $projectId));

                return;
            }
            $output->writeln(sprintf('<error>Error</error>: Purge of the project "%d" is not possible due "%s".', $projectId, $e->getMessage()));
            return;
        }

        $projectName = $deletedProject['name'] ?? 'unknown';
        $output->writeln(sprintf('Purge %s (%d)', $projectName, $projectId));

        if (!$force) {
            $output->writeln("[DRY-RUN] Would purge project $projectId");
            return;
        }

        $response = $client->purgeDeletedProject($projectId, [
            'ignoreBackendErrors' => $ignoreBackendErrors,
        ]);
        $output->writeln(" - execution id {$response['commandExecutionId']}");

        $startTime = time();
        $maxWaitTimeSeconds = 600;
        do {
            $deletedProject = $client->getDeletedProject($projectId);
            if (time() - $startTime > $maxWaitTimeSeconds) {
                throw new \Exception("Project {$projectId} purge timeout.");
            }
            sleep(2);
            $output->writeln(
                sprintf(
                    ' - - Waiting for project "%s" (%s) to be purged: execution id %s',
                    $projectName,
                    $projectId,
                    $response['commandExecutionId'],
                )
            );
        } while ($deletedProject['isPurged'] !== true);

        $output->writeln(sprintf('<info>Purge done "%s" (%d)</info>', $projectName, $projectId));
    }
}
