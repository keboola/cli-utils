<?php

declare(strict_types=1);

namespace Keboola\Console\Command;

use Exception;
use Keboola\ManageApi\Client;
use Keboola\ManageApi\ClientException as ManageClientException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class MassProjectEnableDynamicBackends extends Command
{
    private const ARGUMENT_MANAGE_TOKEN = 'manage-token';
    private const ARGUMENT_CONNECTION_URL = 'connection-url';
    private const ARGUMENT_SOURCE_FILE = 'source-file';
    private const FEATURE_QUEUE_V2 = 'queuev2';
    private const FEATURE_NEW_TRANSFORMATIONS_ONLY = 'new-transformations-only';
    private const FEATURE_DYNAMIC_BACKEND_SIZE = 'workspace-snowflake-dynamic-backend-size';

    protected function configure()
    {
        $this
            ->setName('manage:mass-project-enable-dynamic-backends')
            ->setDescription('Mass project enable dynamic backends')
            ->addArgument(self::ARGUMENT_MANAGE_TOKEN, InputArgument::REQUIRED, 'Manage token')
            ->addArgument(self::ARGUMENT_CONNECTION_URL, InputArgument::REQUIRED, 'Connection url')
            ->addArgument(self::ARGUMENT_SOURCE_FILE, InputArgument::REQUIRED, 'Source file with project ids');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $manageToken = $input->getArgument(self::ARGUMENT_MANAGE_TOKEN);
        $kbcUrl = $input->getArgument(self::ARGUMENT_CONNECTION_URL);
        $sourceFile = $input->getArgument(self::ARGUMENT_SOURCE_FILE);
        $output->writeln(sprintf('Fetching projects from "%s"', $sourceFile));

        $manageClient = new Client([
            'token' => $manageToken,
            'url' => $kbcUrl,
        ]);

        $projects = $this->parseProjectIds($sourceFile);
        $output->writeln(sprintf('Enabling dynamic backends for "%s" projects', count($projects)));

        foreach ($projects as $projectId) {
            try {
                $projectRes = $manageClient->getProject($projectId);

                if (in_array(self::FEATURE_DYNAMIC_BACKEND_SIZE, $projectRes['features'], true)) {
                    $output->writeln(sprintf(' - Project "%s" already has dynamic backends enabled.', $projectId));
                    continue;
                }

                if (!in_array(self::FEATURE_QUEUE_V2, $projectRes['features'], true)) {
                    throw new Exception(sprintf(' - Feature "%s" is missing, project "%s" is not migrated to new queue.', self::FEATURE_QUEUE_V2, $projectId));
                }

                if (!in_array(self::FEATURE_NEW_TRANSFORMATIONS_ONLY, $projectRes['features'], true)) {
                    $output->writeln(sprintf(' - Feature "%s" is missing for project "%s".', self::FEATURE_NEW_TRANSFORMATIONS_ONLY, $projectId));
                    $helper = $this->getHelper('question');
                    $question = new ConfirmationQuestion(
                        ' - Do you want to add this feature (y/n)?',
                        false,
                        '/^(y|j)/i'
                    );
                    if (!$helper->ask($input, $output, $question)) {
                        return;
                    }
                    $manageClient->addProjectFeature($projectId, self::FEATURE_NEW_TRANSFORMATIONS_ONLY);
                    $output->writeln(sprintf(' - Feature "%s" assigned to project "%s".', self::FEATURE_NEW_TRANSFORMATIONS_ONLY, $projectId));
                }

                $response = $manageClient->runCommand([
                    'command' => 'storage:tmp:enable-workspace-snowflake-dynamic-backend-size',
                    'parameters' => [
                        $projectId,
                        '-f'
                    ],
                ]);
                $output->writeln(sprintf(' - Command ID: %s', $response['commandExecutionId']));

                $sleepCount = 0;
                while (1) {
                    if ($sleepCount > 10) {
                        $output->writeln(sprintf(' - Project: %s not enabled check PT for more.', $projectId));
                        return;
                    }
                    $project = $manageClient->getProject($projectId);
                    if (in_array(self::FEATURE_DYNAMIC_BACKEND_SIZE, $project['features'], true)) {
                        break;
                    }
                    $output->writeln(sprintf(' - Command ID: %s [processing]', $response['commandExecutionId']));
                    sleep(5);
                    $sleepCount++;
                }

                $output->writeln(sprintf(' - Project: %s done.', $projectId));
            } catch (ManageClientException $e) {
                $output->writeln(sprintf(
                    'Exception occurred while accessing project %s: %s',
                    $projectId,
                    $e->getMessage()
                ));
            }
        }
    }

    private function parseProjectIds(string $sourceFile): array
    {
        if (!file_exists($sourceFile)) {
            throw new \Exception(sprintf('Cannot open "%s"', $sourceFile));
        }
        $projectsText = trim(file_get_contents($sourceFile));
        if (!$projectsText) {
            return [];
        }

        return explode(PHP_EOL, $projectsText);
    }
}
