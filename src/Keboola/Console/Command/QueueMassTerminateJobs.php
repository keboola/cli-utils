<?php

declare(strict_types=1);

namespace Keboola\Console\Command;

use Keboola\JobQueueClient\Client as JobQueueClient;
use Keboola\JobQueueClient\ListJobsOptions;
use Keboola\StorageApi\Client as StorageClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class QueueMassTerminateJobs extends Command
{
    const ARGUMENT_STORAGE_TOKEN = 'storage-token';
    const ARGUMENT_CONNECTION_URL = 'connection-url';
    const ARGUMENT_JOB_STATUS = 'job-status';

    protected function configure()
    {
        $this
            ->setName('queue:terminate-jobs')
            ->setDescription('Terminated all jobs in project')
            ->addArgument(self::ARGUMENT_STORAGE_TOKEN, InputArgument::REQUIRED, 'Storage token')
            ->addArgument(self::ARGUMENT_CONNECTION_URL, InputArgument::REQUIRED, 'Connection url')
            ->addArgument(self::ARGUMENT_JOB_STATUS, InputArgument::REQUIRED, 'Terminated jobs with this status')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $storageToken = $input->getArgument(self::ARGUMENT_STORAGE_TOKEN);
        $kbcUrl = $input->getArgument(self::ARGUMENT_CONNECTION_URL);
        $status = $input->getArgument(self::ARGUMENT_JOB_STATUS);

        $storageClient = new StorageClient([
            'token' => $storageToken,
            'url' => $kbcUrl,
        ]);

        $tokenRes = $storageClient->verifyToken();

        $projectId = $tokenRes['owner']['id'];
        $output->writeln(sprintf('Terminating jobs with status "%s" in project "%s"', $projectId, $status));
        $output->writeln(PHP_EOL);

        $queueApiUrl = str_replace('connection', 'queue', $kbcUrl);

        $jobQueueClient = new JobQueueClient(
            $queueApiUrl,
            $storageToken
        );

        $jobs = $jobQueueClient->listJobs(
            (new ListJobsOptions())
                ->setStatuses(['waiting'])
                ->setLimit(3000)
        );

        $terminatedJobsIds = [];
        foreach ($jobs as $job) {
            try {
                $jobQueueClient->terminateJob($job['id']);
                $terminatedJobsIds[] = $job['id'];
            } catch (\Throwable $e) {
                $output->writeln($e->getMessage());
            }
        }

        $output->writeln(sprintf('Terminated %s jobs', count($terminatedJobsIds)));
        $output->writeln(PHP_EOL);
    }
}
