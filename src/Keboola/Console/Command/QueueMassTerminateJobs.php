<?php

declare(strict_types=1);

namespace Keboola\Console\Command;

use Exception;
use Keboola\JobQueueClient\Client as JobQueueClient;
use Keboola\JobQueueClient\JobStatuses;
use Keboola\JobQueueClient\ListJobsOptions;
use Keboola\StorageApi\Client as StorageClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class QueueMassTerminateJobs extends Command
{
    protected static $defaultName = 'queue:terminate-project-jobs';

    const ARGUMENT_STORAGE_TOKEN = 'storage-token';
    const ARGUMENT_CONNECTION_URL = 'connection-url';
    const ARGUMENT_JOB_STATUS = 'job-status';

    protected function configure(): void
    {
        $this
            ->setDescription('Terminated all jobs in project')
            ->addArgument(self::ARGUMENT_STORAGE_TOKEN, InputArgument::REQUIRED, 'Storage token')
            ->addArgument(self::ARGUMENT_CONNECTION_URL, InputArgument::REQUIRED, 'Connection url')
            ->addArgument(self::ARGUMENT_JOB_STATUS, InputArgument::REQUIRED, 'Terminated jobs with this status')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storageToken = $input->getArgument(self::ARGUMENT_STORAGE_TOKEN);
        assert(is_string($storageToken));
        $kbcUrl = $input->getArgument(self::ARGUMENT_CONNECTION_URL);
        assert(is_string($kbcUrl));
        $status = $input->getArgument(self::ARGUMENT_JOB_STATUS);
        assert(is_string($status));

        if (!in_array($status, ['created', 'waiting', 'processing'])) {
            throw new Exception('Status must be either "created", "waiting" or "processing"!');
        }

        $storageClient = new StorageClient([
            'token' => $storageToken,
            'url' => $kbcUrl,
        ]);

        $tokenRes = $storageClient->verifyToken();

        $projectId = $tokenRes['owner']['id'];
        $output->writeln(sprintf(
            'Terminating jobs with status "%s" in project "%s"',
            $status,
            $projectId
        ));
        $output->writeln(PHP_EOL);

        $queueApiUrl = str_replace('connection', 'queue', $kbcUrl);

        $jobQueueClient = new JobQueueClient(
            $queueApiUrl,
            $storageToken
        );

        $statusEnum = match ($status) {
            'created' => JobStatuses::CREATED,
            'waiting' => JobStatuses::WAITING,
            default => JobStatuses::PROCESSING,
        };

        $jobs = $jobQueueClient->listJobs(
            (new ListJobsOptions())
                ->setStatuses([$statusEnum])
                ->setLimit(3000)
        );

        $terminatedJobsIds = [];
        foreach ($jobs as $job) {
            try {
                $jobQueueClient->terminateJob($job['id']);
                $output->writeln(sprintf('Terminating job "%s"', $job['id']));
                $terminatedJobsIds[] = $job['id'];
            } catch (\Throwable $e) {
                $output->writeln($e->getMessage());
            }
        }

        $output->writeln(sprintf('Terminated %s jobs', count($terminatedJobsIds)));
        $output->writeln(PHP_EOL);

        return 0;
    }
}
