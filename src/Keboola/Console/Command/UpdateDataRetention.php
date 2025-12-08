<?php

namespace Keboola\Console\Command;

use Keboola\ManageApi\Client;
use Keboola\ManageApi\ClientException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateDataRetention extends Command
{
    const ARG_URL = 'url';
    const ARG_TOKEN = 'token';
    const ARG_DATA_RETENTION = 'dataRetentionTimeInDays';
    const OPT_FORCE = 'force';

    protected int $maintainersChecked = 0;
    protected int $orgsChecked = 0;
    protected int $projectsDisabled = 0;
    protected int $projectsUpdated = 0;
    protected int $projectsError = 0;
    protected int $projectsNoSnowflake = 0;

    protected function configure(): void
    {
        $this
            ->setName('storage:update-data-retention')
            ->setDescription('Update data retention time in days for all projects on the whole stack.')
            ->addArgument(self::ARG_TOKEN, InputArgument::REQUIRED, 'manage token')
            ->addArgument(self::ARG_URL, InputArgument::REQUIRED, 'Stack URL')
            ->addArgument(self::ARG_DATA_RETENTION, InputArgument::REQUIRED, 'Data retention time in days')
            ->addOption(self::OPT_FORCE, 'f', InputOption::VALUE_NONE, 'Will actually do the work, otherwise it\'s dry run');
    }

    protected function createClient(string $host, string $token): Client
    {
        return new Client([
            'url' => $host,
            'token' => $token,
        ]);
    }

    /**
     * @param array<string, mixed> $projectInfo
     */
    protected function updateProjectDataRetention(
        Client $client,
        OutputInterface $output,
        array $projectInfo,
        int $dataRetentionTimeInDays,
        bool $force
    ): void {
        $output->writeln(sprintf('Updating data retention for project "%s" ("%s")', $projectInfo['id'], $projectInfo['name']));

        // Disabled projects
        if (isset($projectInfo["isDisabled"]) && $projectInfo["isDisabled"]) {
            $output->writeln(sprintf(' - project disabled: "%s"', $projectInfo["disabled"]["reason"]));
            $this->projectsDisabled++;
        } else {
            if (!in_array('snowflake', $projectInfo['assignedBackends'])) {
                $output->writeln(' - project does not have Snowflake backend assigned. Skiping.');
                $this->projectsNoSnowflake++;
            } else {
                try {
                    if ($force) {
                        $client->updateProject($projectInfo['id'], ['dataRetentionTimeInDays' => $dataRetentionTimeInDays]);
                        $output->writeln(sprintf(' - data retention time successfully updated to %d days.', $dataRetentionTimeInDays));
                    } else {
                        $output->writeln(sprintf(' - would update data retention time to %d days. Enable force mode with -f option', $dataRetentionTimeInDays));
                    }
                    $this->projectsUpdated++;
                } catch (ClientException $e) {
                    $output->writeln(sprintf(' - error updating project: "%s"', $e->getMessage()));
                    $this->projectsError++;
                }
            }
        }
        $output->write("\n");
    }

    protected function updateAllProjects(
        Client $client,
        OutputInterface $output,
        int $dataRetentionTimeInDays,
        bool $force
    ): void {
        $maintainers = $client->listMaintainers();
        $output->writeln(sprintf('Found %d maintainers', count($maintainers)));

        foreach ($maintainers as $maintainer) {
            $this->maintainersChecked++;
            $output->writeln(sprintf('Processing maintainer "%s" ("%s")', $maintainer['id'], $maintainer['name']));

            $organizations = $client->listMaintainerOrganizations($maintainer['id']);
            $output->writeln(sprintf('Found %d organizations for maintainer "%s"', count($organizations), $maintainer['id']));

            foreach ($organizations as $organization) {
                $this->orgsChecked++;
                $output->writeln('-----');
                $output->writeln(sprintf('Processing organization "%s" ("%s")', $organization['id'], $organization['name']));

                $projects = $client->listOrganizationProjects($organization['id']);
                $output->writeln(sprintf('Found %d projects for organization "%s"', count($projects), $organization['id']));

                foreach ($projects as $project) {
                    $this->updateProjectDataRetention($client, $output, $project, $dataRetentionTimeInDays, $force);
                }
            }
        }
    }

    public function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $args = $input->getArguments();
        $force = (bool) $input->getOption(self::OPT_FORCE);
        $dataRetentionTimeInDays = (int) $args[self::ARG_DATA_RETENTION];
        $client = $this->createClient($args[self::ARG_URL], $args[self::ARG_TOKEN]);

        if ($force) {
            $output->writeln('Force mode enabled. Projects will be updated.');
        } else {
            $output->writeln('Dry run mode. No projects will be updated. Use -f to enable force mode.');
        }

        $output->writeln(sprintf('Updating all projects with data retention time: %d days', $dataRetentionTimeInDays));
        $this->updateAllProjects($client, $output, $dataRetentionTimeInDays, $force);

        $output->writeln("\n" . 'DONE with following results:' . "\n");
        $this->printResult($output, $force);

        return 0;
    }

    private function printResult(OutputInterface $output, bool $force): void
    {
        $output->writeln(sprintf(
            'Checked %d maintainers' . "\n"
            . 'Checked %d organizations' . "\n"
            . '%d projects were disabled' . "\n"
            . '%d projects do not have Snowflake backend' . "\n"
            . '%d ' . ($force ? 'projects updated' : 'projects would be updated in force mode') . "\n"
            . '%d projects had errors during update' . "\n",
            $this->maintainersChecked,
            $this->orgsChecked,
            $this->projectsDisabled,
            $this->projectsNoSnowflake,
            $this->projectsUpdated,
            $this->projectsError
        ));
    }
}
