<?php

declare(strict_types=1);

namespace Keboola\Console\Command;

use InvalidArgumentException;
use Keboola\Csv\CsvFile;
use Keboola\JobQueueClient\JobData;
use Keboola\Sandboxes\Api\Client as SandboxesClient;
use Keboola\JobQueueClient\Client as QueueClient;
use Keboola\Sandboxes\Api\ListOptions;
use Keboola\Sandboxes\Api\Sandbox;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\DevBranches;
use Keboola\StorageApi\Workspaces;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class MassDeleteProjectWorkspaces extends Command
{
    private const ARGUMENT_STACK_SUFFIX = 'stack-suffix';
    private const ARGUMENT_SOURCE_FILE = 'source-file';
    private const OPTION_FORCE = 'force';

    protected function configure(): void
    {
        $this
            ->setName('manage:mass-delete-project-workspaces')
            ->setDescription('Delete all project workspaces based on given list in file. [Works only for SNFLK now].')
            ->addArgument(self::ARGUMENT_STACK_SUFFIX, InputArgument::REQUIRED, 'stack suffix "keboola.com, eu-central-1.keboola.com"')
            ->addArgument(self::ARGUMENT_SOURCE_FILE, InputArgument::REQUIRED, 'Source csv with "project id,workspace schema" columns and no header.')
            ->addOption(self::OPTION_FORCE, 'f', InputOption::VALUE_NONE, 'Write changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stackSuffix = $input->getArgument(self::ARGUMENT_STACK_SUFFIX);
        assert(is_string($stackSuffix));
        $connectionUrl = 'https://connection.' . $stackSuffix;
        $sandboxesUrl = 'https://sandboxes.' . $stackSuffix;
        $jobsUrl = 'https://queue.' . $stackSuffix;
        $sourceFile = $input->getArgument(self::ARGUMENT_SOURCE_FILE);
        assert(is_string($sourceFile));
        $output->writeln(sprintf('Fetching projects from "%s"', $sourceFile));
        $force = (bool) $input->getOption(self::OPTION_FORCE);

        // map by project id
        /**
         * @var array<string, array<int, string>> $map
         */
        $map = [];
        $csv = new CsvFile($sourceFile);
        foreach ($csv as $line) {
            assert(is_array($line));
            if (count($line) !== 2) {
                throw new InvalidArgumentException('File must contain exactly two columns.');
            }
            $projectId = $line[0];
            $workspaceSchema = $line[1];
            assert(is_string($projectId) || is_numeric($projectId));
            assert(is_string($workspaceSchema));
            if (!is_numeric($projectId)) {
                throw new InvalidArgumentException(sprintf('Project id "%s" is not numeric.', $projectId));
            }
            if (!str_starts_with($workspaceSchema, 'WORKSPACE_')) {
                throw new InvalidArgumentException(sprintf('Workspace "%s" does not start with "WORKSPACE_".', $workspaceSchema));
            }

            $projectIdStr = (string) $projectId;
            if (array_key_exists($projectIdStr, $map)) {
                $map[$projectIdStr][] = $workspaceSchema;
            } else {
                $map[$projectIdStr] = [$workspaceSchema];
            }
        }

        // testing override
//        $map = [
//            '232' => [
//                'WORKSPACE_832798053',
//                'WORKSPACE_965913339',
//            ],
//        ];

        foreach ($map as $projectId => $workspacesSchemasToDelete) {
            /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new Question(sprintf(
                'Paste storage token for project "%s" to continue.' . PHP_EOL,
                $projectId,
            ));
            $storageToken = $helper->ask($input, $output, $question);
            assert(is_string($storageToken));

            $storageClient = new Client([
                'token' => $storageToken,
                'url' => $connectionUrl,
            ]);
            $sandboxesClient = new SandboxesClient(
                $sandboxesUrl,
                $storageToken
            );
            $jobsClient = new QueueClient(
                $jobsUrl,
                $storageToken
            );

            $branchesClient = new DevBranches($storageClient);

            $jobs = [];
            foreach ($branchesClient->listBranches() as $branch) {
                $output->writeln(sprintf('Checking branch "%s" for sandboxes.', $branch['id']));
                $branchId = (string) $branch['id'];
                if ($branch['isDefault']) {
                    $branchId = null;
                }
                /** @var Sandbox $sandbox */
                foreach ($sandboxesClient->list((new ListOptions())->setBranchId($branchId)) as $sandbox) {
                    $schema = $sandbox->getWorkspaceDetails()['connection']['schema'] ?? null;
                    if (!in_array($schema, $workspacesSchemasToDelete, true)) {
                        continue;
                    }
                    $output->writeln(sprintf(
                        'Sandbox "%s" with schema "%s" found.',
                        $sandbox->getId(),
                        $schema,
                    ));

                    // remove found schema from map
                    unset($map[$projectId][array_search($schema, $map[$projectId], true)]);

                    if ($force) {
                        $job = $jobsClient->createJob(new JobData(
                            'keboola.sandboxes',
                            null,
                            [
                                'parameters' => [
                                    'task' => 'delete',
                                    'id' => $sandbox->getId(),
                                ],
                            ],
                        ));

                        $job['sandbox'] = $sandbox;
                        $jobs[] = $job;

                        $output->writeln(sprintf(
                            'Created delete job "%s" for project "%s"',
                            $job['id'],
                            $projectId
                        ));
                    } else {
                        $output->writeln(sprintf(
                            '[DRY-RUN] Created delete job "%s" for project "%s"',
                            '<some job id>',
                            $projectId
                        ));
                    }
                }
            }

            $output->writeln('Waiting for delete jobs to finish.');
            while (count($jobs) > 0) {
                foreach ($jobs as $i => $job) {
                    $jobRes = $jobsClient->getJob((string) $job['id']);
                    if ($jobRes['isFinished'] === true) {
                        $output->writeln(sprintf(
                            'Delete job "%s" for sandbox "%s" with schema "%s" finished with status "%s"',
                            $job['id'],
                            $job['sandbox']->getId(),
                            $job['sandbox']->getWorkspaceDetails()['connection']['schema'],
                            $jobRes['status']
                        ));
                        unset($jobs[$i]);
                    }
                }
                sleep(2);
            }

            foreach ($branchesClient->listBranches() as $branch) {
                $output->writeln(sprintf('Checking branch "%s" for storage workspaces.', $branch['id']));
                $workspacesClient = new Workspaces(
                    $storageClient->getBranchAwareClient($branch['id'])
                );
                foreach ($workspacesClient->listWorkspaces() as $workspace) {
                    if (!in_array($workspace['connection']['schema'], $map[$projectId], true)) {
                        continue;
                    }
                    // remove found schema from map
                    unset($map[$projectId][array_search($workspace['connection']['schema'], $map[$projectId], true)]);
                    if ($force) {
                        $output->writeln(sprintf('Deleting workspace "%s" with schema "%s"', $workspace['id'], $workspace['connection']['schema']));
                        $workspacesClient->deleteWorkspace($workspace['id'], [], true);
                    } else {
                        $output->writeln(sprintf('[DRY-RUN] Deleting workspace "%s" with schema "%s"', $workspace['id'], $workspace['connection']['schema']));
                    }
                }
            }

            if (count($map[$projectId]) !== 0) {
                $output->writeln([
                    '<error>Following schemas were not found (are deleted or needs to be deleted manually):</error>',
                    implode(', ', $map[$projectId]),
                ]);
            }
        }

        return 0;
    }
}
