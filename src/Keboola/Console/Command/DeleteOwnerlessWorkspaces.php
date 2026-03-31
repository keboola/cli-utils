<?php

namespace Keboola\Console\Command;

use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client as StorageApiClient;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Tokens;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteOwnerlessWorkspaces extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('storage:delete-ownerless-workspaces')
            ->setDescription('Bulk delete workspaces that have inactive owner in this project.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Use [--force, -f] to do it for real.')
            ->addOption(
                'includeShared',
                null,
                InputOption::VALUE_NONE,
                'Use option --includeShared if you would also like to delete shared workspaces with inactive owner.',
            )
            ->addArgument(
                'storageToken',
                InputArgument::REQUIRED,
                'Keboola Storage API token to use'
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
        $token = $input->getArgument('storageToken');
        assert(is_string($token));
        $hostnameSuffix = $input->getArgument('hostnameSuffix');
        assert(is_string($hostnameSuffix));
        $url = 'https://connection.' . $hostnameSuffix;
        $editorUrl = 'https://editor.' . $hostnameSuffix;
        $includeShared = (bool) $input->getOption('includeShared');
        $force = (bool) $input->getOption('force');

        $storageClient = new StorageApiClient([
            'token' => $token,
            'url' => $url,
            'backoffMaxTries' => 1,
            'logger' => new ConsoleLogger($output),
        ]);
        $tokensClient = new Tokens($storageClient);
        $editorClient = new EditorServiceClient($editorUrl, $token);

        if ($force) {
            $output->writeln('Force option is set, doing it for real');
        } else {
            $output->writeln('This is just a dry-run, nothing will be actually deleted');
        }

        // Build a set of active user IDs from project tokens
        $activeUserIds = [];
        foreach ($tokensClient->listTokens() as $projectToken) {
            if (isset($projectToken['admin']['id'])) {
                $activeUserIds[$projectToken['admin']['id']] = true;
            }
        }

        $totalDeleted = 0;

        foreach ($editorClient->listSessions() as $session) {
            $userId = $session['userId'] ?? null;
            if ($userId !== null && isset($activeUserIds[$userId])) {
                continue; // user is still active
            }

            if (!$includeShared && !empty($session['shared'])) {
                continue;
            }

            $branchId = (string) $session['branchId'];
            $componentId = $session['componentId'];
            $configurationId = $session['configurationId'];

            $output->writeln(sprintf(
                'Deleting configuration %s/%s (branch %s) for session %s',
                $componentId,
                $configurationId,
                $branchId,
                $session['id'],
            ));

            $totalDeleted++;
            if ($force) {
                $branchClient = new BranchAwareClient($branchId, [
                    'token' => $token,
                    'url' => $url,
                ]);
                $components = new Components($branchClient);
                $components->deleteConfiguration($componentId, $configurationId);
                $components->deleteConfiguration($componentId, $configurationId);
            }
        }

        $output->writeln(sprintf('%d sessions deleted', $totalDeleted));

        return 0;
    }
}
