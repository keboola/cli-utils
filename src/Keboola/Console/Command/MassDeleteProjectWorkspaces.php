<?php

declare(strict_types=1);

namespace Keboola\Console\Command;

use Keboola\Csv\CsvFile;
use Keboola\Sandboxes\Api\Client as SandboxesClient;
use Keboola\Sandboxes\Api\Sandbox;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException as StorageApiClientException;
use Keboola\StorageApi\Components;
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

    protected function configure()
    {
        $this
            ->setName('manage:mass-delete-project-workspaces')
            ->setDescription('Delete all project workspaces based on given list in file. [Works only for SNFLK now].')
            ->addArgument(self::ARGUMENT_STACK_SUFFIX, InputArgument::REQUIRED, 'stack suffix "keboola.com, eu-central-1.keboola.com"')
            ->addArgument(self::ARGUMENT_SOURCE_FILE, InputArgument::REQUIRED, 'Source csv with "prjId,workspaceSchema" columns')
            ->addOption(self::OPTION_FORCE, 'f', InputOption::VALUE_NONE, 'Write changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $connectionUrl = 'https://connection.' . $input->getArgument(self::ARGUMENT_STACK_SUFFIX);
        $sandboxesUrl = 'https://sandboxes.' . $input->getArgument(self::ARGUMENT_STACK_SUFFIX);
        $sourceFile = $input->getArgument(self::ARGUMENT_SOURCE_FILE);
        $output->writeln(sprintf('Fetching projects from "%s"', $sourceFile));
        $force = $input->getOption(self::OPTION_FORCE);

        // map by project id
        /**
         * @var array{
         *     string,
         *     string[],
         * } $map
         */
        $map = [];
        $csv = new CsvFile($sourceFile);
        foreach ($csv as $i => $line) {
            if ($i === 0) {
                // skip header
                continue;
            }
            if (array_key_exists($line[0], $map)) {
                $map[$line[0]][] = $line[1];
            } else {
                $map[$line[0]] = [$line[1]];
            }
        }

        // testing override
//        $map = [
//            '232' => [
//                'WORKSPACE_832798053',
//                'WORKSPACE_965913339',
//            ],
//        ];

        foreach ($map as $projectId => $workspaces) {
            $helper = $this->getHelper('question');
            $question = new Question(sprintf(
                'Paster storage token for project "%s" to continue.' . PHP_EOL,
                $projectId,
            ));
            $storageToken = $helper->ask($input, $output, $question);

            $storageClient = new Client([
                'token' => $storageToken,
                'url' => $connectionUrl,
            ]);
            $workspacesClient = new Workspaces($storageClient);
            $componentsClient = new Components($storageClient);
            $sandboxesClient = new SandboxesClient(
                $sandboxesUrl,
                $storageToken
            );

            /** @var Sandbox $sandbox */
            foreach ($sandboxesClient->list() as $sandbox) {
                if ($sandbox->getWorkspaceDetails() === [] || !array_key_exists('connection', $sandbox->getWorkspaceDetails())) {
                    continue; // skip sandboxes
                }
                foreach ($workspaces as $schema) {
                    $output->writeln(sprintf('Checking sandbox "%s" with schema "%s"', $sandbox->getWorkspaceDetails()['connection']['schema'], $schema));
                    if ($schema === $sandbox->getWorkspaceDetails()['connection']['schema']) {
                        $output->writeln(sprintf(
                            'Sandbox "%s" with schema "%s" found.',
                            $sandbox->getId(),
                            $schema,
                        ));
                        // remove found schema from map
                        unset($map[$projectId][array_search($schema, $map[$projectId])]);

                        $output->writeln('Looking for configuration.');
                        $configuration = null;
                        try {
                            $configuration = $componentsClient->getConfiguration('keboola.sandboxes', $sandbox->getConfigurationId());
                            $output->writeln(sprintf(
                                'Configuration "%s" found.',
                                $configuration['id'],
                            ));
                        } catch (StorageApiClientException $e) {
                            $output->writeln(sprintf(
                                'Configuration "keboola.sandboxes"->"%s" not found.',
                                $sandbox->getConfigurationId(),
                            ));
                        }

                        $output->writeln('Looking for storage workspace.');
                        $storageWorkspace = null;
                        try {
                            $storageWorkspace = $workspacesClient->getWorkspace($sandbox->getPhysicalId());
                            $output->writeln(sprintf(
                                'Storage workspace "%s" found.',
                                $storageWorkspace['id'],
                            ));
                        } catch (StorageApiClientException $e) {
                            $output->writeln(sprintf(
                                'Workspace "%s" not found.',
                                $sandbox->getPhysicalId(),
                            ));
                        }
                        // workspace is sandbox and we can delete configuration,sandbox and workspace
                        if ($force) {
                            $output->writeln(sprintf('Deleting sandbox "%s" with schema "%s"', $sandbox->getId(), $schema));
                            $sandboxesClient->delete($sandbox->getId());
                            if ($configuration !== null) {
                                $output->writeln(sprintf('Deleting configuration "%s"', $configuration['id']));
                                $componentsClient->deleteConfiguration('keboola.sandboxes', $configuration['id']);
                            }
                            if ($storageWorkspace !== null) {
                                $output->writeln(sprintf('Deleting storage workspace "%s"', $storageWorkspace['id']));
                                $workspacesClient->deleteWorkspace($storageWorkspace['id']);
                            }
                        } else {
                            $output->writeln('[DRY-RUN] Resources would be deleted');
                        }
                    }
                }
            }

            foreach ($workspacesClient->listWorkspaces() as $workspace) {
                foreach ($map[$projectId] as $workspaceOnlyInStorage) {
                    if ($workspace['connection']['schema'] === $workspaceOnlyInStorage) {
                        $output->writeln(sprintf(
                            'Workspace "%s" with schema "%s" found.',
                            $workspace['id'],
                            $workspaceOnlyInStorage,
                        ));
                        // remove found schema from map
                        unset($map[$projectId][array_search($workspaceOnlyInStorage, $map[$projectId])]);
                        if ($force) {
                            $output->writeln(sprintf('Deleting workspace "%s" with schema "%s"', $workspace['id'], $workspaceOnlyInStorage));
                            $workspacesClient->deleteWorkspace($workspace['id']);
                        } else {
                            $output->writeln('[DRY-RUN] Resources would be deleted');
                        }
                    }
                }
            }

            if (count($map[$projectId]) !== 0) {
                $output->writeln([
                    sprintf('Following schemas were not found and needs to be deleted manually:'),
                    ...$map[$projectId],
                ]);
            }
        }
    }
}
