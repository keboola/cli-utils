<?php

namespace Keboola\Console\Command;

use Keboola\JobQueueClient\Client as QueueClient;
use Keboola\JobQueueClient\ListJobsOptions;
use Keboola\ManageApi\Client as ManageClient;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\Tokens;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationIntoMaintenanceMode extends Command
{
    const ARGUMENT_MANAGE_TOKEN = 'manageToken';
    const ARGUMENT_ORGANIZATION_ID = 'organizationId';
    const ARGUMENT_MAINTENANCE_MODE = 'maintenanceMode';
    const ARGUMENT_REASON = 'disableReason';
    const ARGUMENT_ESTIMATED_END_TIME = 'estimatedEndTime';
    const ARGUMENT_HOSTNAME_SUFFIX = 'hostnameSuffix';
    const OPTION_FORCE = 'force';

    protected function configure()
    {
        $this
            ->setName('manage:set-organization-maintenance-mode')
            ->setDescription('Set maintenance mode for all projects in an organization')
            ->addOption(
                self::OPTION_FORCE,
                'f',
                InputOption::VALUE_NONE,
                'Use [--force, -f] to do it for real.'
            )
            ->addArgument(self::ARGUMENT_MANAGE_TOKEN, InputArgument::REQUIRED, 'Maname Api Token')
            ->addArgument(self::ARGUMENT_ORGANIZATION_ID, InputArgument::REQUIRED, 'Organization Id')
            ->addArgument(
                self::ARGUMENT_MAINTENANCE_MODE,
                InputArgument::REQUIRED,
                'use "on" to turn on maintenance mode, and "off" to turn it off'
            )
            ->addArgument(
                self::ARGUMENT_HOSTNAME_SUFFIX,
                InputArgument::OPTIONAL,
                'Keboola Connection Hostname Suffix',
                'keboola.com'
            )
            ->addArgument(
                self::ARGUMENT_REASON,
                InputArgument::OPTIONAL,
                'Reason for maintenance (ex Migration)'
            )
            ->addArgument(
                self::ARGUMENT_ESTIMATED_END_TIME,
                InputArgument::OPTIONAL,
                'Estimated time of maintenance (ex + 5 hours)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $maintenanceMode = $input->getArgument(self::ARGUMENT_MAINTENANCE_MODE);
        if (!in_array($maintenanceMode, ['on', 'off'])) {
            throw new \Exception(sprintf(
                'The argument "%s" must be either "on" or "off", not "%s"',
                self::ARGUMENT_MAINTENANCE_MODE,
                $maintenanceMode
            ));
        }
        $manageToken = $input->getArgument(self::ARGUMENT_MANAGE_TOKEN);
        $reason = $input->getArgument(self::ARGUMENT_REASON);
        $estimatedEndTime = $input->getArgument(self::ARGUMENT_ESTIMATED_END_TIME);
        $organizationId = $input->getArgument(self::ARGUMENT_ORGANIZATION_ID);
        $kbcUrl = sprintf('https://connection.%s', $input->getArgument(self::ARGUMENT_HOSTNAME_SUFFIX));

        $manageClient = new ManageClient(['token' => $manageToken, 'url' => $kbcUrl]);

        $organization = $manageClient->getOrganization($organizationId);
        $projects = $organization['projects'];

        $output->writeln(
            sprintf(
                'Will put "%d" projects "%s" maintenance mode',
                count($projects),
                $maintenanceMode
            )
        );
        $force = $input->getOption(self::OPTION_FORCE);
        $output->writeln($force ? 'FORCE MODE' : 'DRY RUN');
        $params = [];
        if ($reason) {
            $params[self::ARGUMENT_REASON] = $reason;
        }
        if ($estimatedEndTime) {
            $params[self::ARGUMENT_ESTIMATED_END_TIME] = $estimatedEndTime;
        }
        foreach ($projects as $project) {
            $output->writeln(
                sprintf(
                    'Putting project %s %s maintenance mode',
                    $project['id'],
                    $maintenanceMode
                )
            );
            if ($maintenanceMode === 'on' && $force) {
                $output->writeln(
                    'Checking the project for any running jobs',
                );
                $thereAreRunningJobs = $this->areThereRunningJobs(
                    $manageClient,
                    $project['id'],
                    $input->getArgument(self::ARGUMENT_HOSTNAME_SUFFIX),
                    $output
                );
                if ($thereAreRunningJobs) {
                    continue;
                }
            }

            if ($force) {
                if ($maintenanceMode === 'on') {
                    $manageClient->disableProject(
                        $project['id'],
                        $params
                    );
                } elseif ($maintenanceMode === 'off') {
                    $manageClient->enableProject(
                        $project['id']
                    );
                }
            }
        }
        $output->writeln('All done.');
    }

    private function areThereRunningJobs(
        ManageClient    $manageClient,
        string          $projectId,
        string          $hostnameSuffix,
        OutputInterface $output
    ): bool {

        // We need to create a storage token in order to use the Jobs API
        $storageToken = $manageClient->createProjectStorageToken(
            $projectId,
            ['description' => 'Maintenance: Terminating Jobs prior to disabling projects']
        );
        $jobsClient = new QueueClient(
            sprintf('https://queue.%s', $hostnameSuffix),
            $storageToken['token']
        );
        $runningJobsListOptions = new ListJobsOptions();
        // created, waiting, processing, terminating
        $runningJobsListOptions->setStatuses([
            ListJobsOptions::STATUS_CREATED,
            ListJobsOptions::STATUS_WAITING,
            ListJobsOptions::STATUS_PROCESSING,
            ListJobsOptions::STATUS_TERMINATING
        ]);
        $runningJobs = $jobsClient->listJobs($runningJobsListOptions);
        $output->writeln(
            sprintf(
                'Found %d running jobs.  Please terminate them and then re-run',
                count($runningJobs)
            )
        );
        foreach ($runningJobs as $runningJob) {
            $output->writeln(
                $runningJob['url'] . ' is ' . $runningJob['status']
            );
        }
        // drop the token
        $tokensClient = new Tokens(
            new StorageClient([
                'token' => $storageToken['token'],
                'url' => sprintf('https://connection.%s', $hostnameSuffix),
            ])
        );
        $tokensClient->dropToken($storageToken['id']);

        return count($runningJobs) > 0;
    }
}
