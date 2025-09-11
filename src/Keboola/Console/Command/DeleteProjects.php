<?php

namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Keboola\ManageApi\ClientException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteProjects extends Command
{
    private int $projectsDisabled = 0;
    private int $projectsFailed = 0;
    private int $projectsDeleted = 0;

    protected function configure(): void
    {
        $this
            ->setName('manage:delete-projects')
            ->setDescription('Delete all projects specified by project IDs')
            ->addArgument('token', InputArgument::REQUIRED, 'manage token')
            ->addArgument('url', InputArgument::REQUIRED, 'Stack URL. Including https://')
            ->addArgument('projects', InputArgument::REQUIRED, 'list of IDs separated by comma ("1,7,146")')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Will actually do the work, otherwise it\'s dry run');
    }

    public function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $apiToken = $input->getArgument('token');
        $apiUrl = $input->getArgument('url');
        $projects = $input->getArgument('projects');

        $force = (bool) $input->getOption('force');

        $client = $this->createClient($apiUrl, $apiToken);

        $projectIds = array_filter(explode(',', $projects), 'is_numeric');
        $this->deleteProjects($client, $output, $projectIds, $force);
        $output->writeln('');

        $output->writeln('DONE with following results:');
        $this->printResult($output);

        if (!$force) {
            $output->writeln('');
            $output->writeln('Command was run in <comment>dry-run</comment> mode. To actually apply changes run it with --force flag.');
        }

        return 0;
    }

    private function createClient(string $host, string $token): Client
    {
        return new Client([
            'url' => $host,
            'token' => $token,
        ]);
    }

    private function deleteProjects(
        Client $client,
        OutputInterface $output,
        array $projectIds,
        bool $force
    ): void {
        foreach ($projectIds as $projectId) {
            $output->write(sprintf('Project <comment>%s</comment>: ', $projectId));

            try {
                $project = $client->getProject($projectId);
                $this->deleteSingleProject($client, $output, $project, $force);
            } catch (ClientException $e) {
                if ($e->getCode() === 404) {
                    $output->writeln('<error>not found</error>');
                } else {
                    $output->writeln(sprintf('<error>error</error>: %s', $e->getMessage()));
                }
                $this->projectsFailed++;
            }
        }
    }

    private function deleteSingleProject(
        Client $client,
        OutputInterface $output,
        array $projectInfo,
        bool $force
    ): void {
        if (isset($projectInfo['isDisabled']) && $projectInfo['isDisabled']) {
            $output->writeln('project is disabled, <comment>skipping</comment>');
            $this->projectsDisabled++;

            return;
        }

        if ($force) {
            $client->deleteProject($projectInfo['id']);

            $projectDetail = $client->getDeletedProject($projectInfo['id']);
            if (!$projectDetail['isDeleted']) {
                $output->writeln(
                    sprintf('<err>project "%s" deletion failed</err>', $projectDetail['id'])
                );
                $this->projectsFailed++;

                return;
            }
            $output->writeln(
                sprintf('<info>project "%s" has been deleted</info>', $projectDetail['id'])
            );

            $this->projectsDeleted++;
        } else {
            $output->writeln(
                sprintf('<info>[DRY-RUN] would delete project "%s"</info>', $projectInfo['id'])
            );
        }
    }

    private function printResult(OutputInterface $output): void
    {
        $output->writeln(sprintf('  %d projects disabled', $this->projectsDisabled));
        $output->writeln(sprintf('  %d projects deleted', $this->projectsDeleted));
        $output->writeln(sprintf('  %d projects failed', $this->projectsFailed));
    }
}
