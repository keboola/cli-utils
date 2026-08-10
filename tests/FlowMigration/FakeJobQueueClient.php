<?php

declare(strict_types=1);

namespace Keboola\Console\Tests\FlowMigration;

use Keboola\JobQueueClient\Client as JobQueueClient;
use Keboola\JobQueueClient\DTO\Job;
use Keboola\JobQueueClient\JobData;
use Keboola\JobQueueClient\ListJobsOptions;
use RuntimeException;
use Throwable;

/**
 * In-memory double for Keboola\JobQueueClient\Client with scripted responses.
 * Deliberately does not call parent::__construct() so no HTTP client is ever
 * created - same trick as FakeComponents.
 */
class FakeJobQueueClient extends JobQueueClient
{
    /** @var array<int, array<string, mixed>> recorded JobData->getArray() payloads */
    public array $createdJobs = [];

    /** @var array<int, array<int, string>> ordered call log: [method, id] */
    public array $calls = [];

    public int $listJobsCalls = 0;

    /** @var array<int, Job> */
    private array $createJobReturns;

    /** @var array<string, array<int, Job|Throwable>> */
    private array $getJobSequences;

    /** @var array<int, Job> */
    private array $listJobsReturn;

    /**
     * @param array<int, Job> $createJobReturns successive createJob() returns
     * @param array<string, array<int, Job|Throwable>> $getJobSequences jobId => successive getJob() outcomes
     * @param array<int, Job> $listJobsReturn returned by every listJobs() call
     */
    public function __construct(array $createJobReturns = [], array $getJobSequences = [], array $listJobsReturn = [])
    {
        $this->createJobReturns = $createJobReturns;
        $this->getJobSequences = $getJobSequences;
        $this->listJobsReturn = $listJobsReturn;
    }

    public function createJob(JobData $jobData): Job
    {
        $this->createdJobs[] = $jobData->getArray();
        $job = array_shift($this->createJobReturns);
        if ($job === null) {
            throw new RuntimeException('FakeJobQueueClient: no scripted createJob return left');
        }
        $this->calls[] = ['createJob', $job->id];

        return $job;
    }

    public function getJob(string $jobId): Job
    {
        $this->calls[] = ['getJob', $jobId];
        $sequence = $this->getJobSequences[$jobId] ?? [];
        if ($sequence === []) {
            throw new RuntimeException(
                sprintf('FakeJobQueueClient: no scripted getJob outcome left for "%s"', $jobId)
            );
        }
        $outcome = array_shift($sequence);
        $this->getJobSequences[$jobId] = $sequence;
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return $outcome;
    }

    public function listJobs(ListJobsOptions $listOptions): array
    {
        $this->calls[] = ['listJobs'];
        $this->listJobsCalls++;

        return $this->listJobsReturn;
    }

    /**
     * Builds a real DTO\Job through its public factory so the fixture stays in sync with the SDK.
     *
     * @param array<mixed>|null $result
     */
    public static function makeJob(
        string $id,
        string $status,
        ?int $durationSeconds = null,
        ?array $result = null
    ): Job {
        $terminalStatuses = ['success', 'error', 'warning', 'terminated', 'cancelled'];

        return Job::fromApiResponse([
            'id' => $id,
            'runId' => $id,
            'parentRunId' => '',
            'project' => ['id' => '123'],
            'token' => ['id' => '456', 'description' => 'test token'],
            'status' => $status,
            'desiredStatus' => 'processing',
            'mode' => 'run',
            'component' => 'keboola.flow-migration-tool',
            'config' => null,
            'configData' => null,
            'configRowIds' => null,
            'tag' => null,
            'createdTime' => '2026-08-10T10:00:00+00:00',
            'startTime' => null,
            'endTime' => null,
            'durationSeconds' => $durationSeconds,
            'result' => $result,
            'usageData' => null,
            'isFinished' => in_array($status, $terminalStatuses, true),
            'url' => sprintf('https://queue.example.com/jobs/%s', $id),
            'branchId' => null,
            'variableValuesId' => null,
            'variableValuesData' => [],
            'backend' => [],
            'behavior' => [],
            'executor' => null,
            'metrics' => null,
            'parallelism' => null,
            'type' => 'standard',
            'orchestrationJobId' => null,
            'orchestrationTaskId' => null,
            'onlyOrchestrationTaskIds' => null,
            'previousJobId' => null,
        ]);
    }
}
