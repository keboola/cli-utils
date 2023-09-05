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
    const OPTION_TERMINATE_JOBS = 'terminateJobs';

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
            ->addOption(
                self::OPTION_TERMINATE_JOBS,
                't',
                InputOption::VALUE_NONE,
                'Use [--terminateJobs, -t] to terminate running jobs prior to setting maintenance mode'
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
            if ($input->getOption(self::OPTION_TERMINATE_JOBS)) {
                if ($maintenanceMode === 'on') {
                    $this->terminateRunningJobs(
                        $manageClient,
                        $project['id'],
                        $input->getArgument(self::ARGUMENT_HOSTNAME_SUFFIX),
                        $output,
                        $force
                    );
                } else {
                    $output->writeln(
                        'Skipping job termination if not turning on maintenance mode',
                    );
                }
            }

            $output->writeln(
                sprintf(
                    'Putting project %s %s maintenance mode',
                    $project['id'],
                    $maintenanceMode
                )
            );
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

    private function terminateRunningJobs(
        ManageClient    $manageClient,
        string          $projectId,
        string          $hostnameSuffix,
        OutputInterface $output,
        bool            $force
    ): void
    {
        // We need to create a storage token in order to use the Jobs API
        $output->writeln('Creating temporary storage token');
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
        $output->writeln(
            sprintf(
                'Gathering running jobs for Project %s',
                $projectId
            )
        );
        $runningJobs = $jobsClient->listJobs($runningJobsListOptions);
        $output->writeln(
            sprintf(
                'Found %d running jobs',
                count($runningJobs)
            )
        );

        $terminatingJobs = [];
        foreach ($runningJobs as $runningJob) {
            if ($runningJob['status'] !== ListJobsOptions::STATUS_TERMINATING) {
                $output->writeln(
                    sprintf(
                        'Terminating job %s that had status %s',
                        $runningJob['id'],
                        $runningJob['status']
                    )
                );
                $jobsClient->terminateJob($runningJob['id']);
            }
            $terminatingJobs[] = $runningJob;
        }
        while (!empty($terminatingJobs)) {
            sleep(2);
            foreach ($terminatingJobs as $key => $terminatingJob) {
                $jobDetails = $jobsClient->getJob($terminatingJob['id']);
                $output->writeln(
                    sprintf(
                        'Checking whether termination is complete job %s that had status %s',
                        $terminatingJob['id'],
                        $terminatingJob['status']
                    )
                );
                if ($jobDetails['status'] === ListJobsOptions::STATUS_TERMINATED) {
                    unset($terminatingJobs[$key]);
                }
            }
        }
        // Don't need the storage token anymore
        $tokensClient = new Tokens(
            new StorageClient([
                'token' => $storageToken['token'],
                'url' => sprintf('https://queue.%s', $hostnameSuffix),
            ])
        );
        $output->writeln('Deleting temporary storage token');
        $tokensClient->dropToken($storageToken['id']);
    }
}
