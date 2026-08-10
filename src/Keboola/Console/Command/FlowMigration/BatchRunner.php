<?php

declare(strict_types=1);

namespace Keboola\Console\Command\FlowMigration;

use Keboola\JobQueueClient\Client as JobQueueClient;
use Keboola\JobQueueClient\DTO\Job;
use Keboola\JobQueueClient\JobData;
use Keboola\JobQueueClient\JobStatuses;
use Keboola\JobQueueClient\ListJobsOptions;
use Keboola\ManageApi\ClientException as ManageClientException;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Drives keboola.flow-migration-tool jobs across a batch of projects with a bounded
 * concurrency window. Pure orchestration - all migration logic lives in the component.
 *
 * @phpstan-type BatchSummary array{
 *     attempted: int,
 *     migrated: int,
 *     migratedWithWarning: int,
 *     skippedNoOrchestrations: int,
 *     skippedDisabled: int,
 *     skippedJobRunning: int,
 *     failed: int
 * }
 * @phpstan-type InFlightJob array{
 *     projectId: string,
 *     jobId: string,
 *     queueClient: JobQueueClient,
 *     startedAt: float,
 *     pollFailures: int
 * }
 */
class BatchRunner
{
    public const ORCHESTRATOR_COMPONENT_ID = 'keboola.orchestrator';
    public const MIGRATION_COMPONENT_ID = 'keboola.flow-migration-tool';

    // TERMINATING is included on top of the issue's created/waiting/processing: a terminating
    // job may still be executing migration writes, and skipping it strictly reduces overlap risk.
    private const LIVE_JOB_STATUSES = [
        JobStatuses::CREATED,
        JobStatuses::WAITING,
        JobStatuses::PROCESSING,
        JobStatuses::TERMINATING,
    ];

    // A transient Queue API outage must not fail a project instantly (the SDK already retries
    // 5xx internally), but an unbounded retry could hang the batch forever - so give up after
    // this many consecutive failed polls and leave the job to finish server-side.
    private const MAX_CONSECUTIVE_POLL_FAILURES = 3;

    private ProjectClientsFactory $clientsFactory;
    private int $concurrency;
    private int $pollIntervalSeconds;

    /** @var callable(int): void */
    private $sleep;

    public function __construct(
        ProjectClientsFactory $clientsFactory,
        int $concurrency,
        int $pollIntervalSeconds,
        ?callable $sleep = null
    ) {
        $this->clientsFactory = $clientsFactory;
        // A window smaller than one job would never let the run loop drain the pending queue,
        // i.e. it would hang the batch forever - clamp instead of spinning.
        $this->concurrency = max(1, $concurrency);
        $this->pollIntervalSeconds = $pollIntervalSeconds;
        $this->sleep = $sleep ?? function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @param array<int, string> $projectIds
     * @param callable(ProjectResult): void $onProjectFinished invoked once per input project
     * @return BatchSummary
     */
    public function run(array $projectIds, bool $force, OutputInterface $output, callable $onProjectFinished): array
    {
        $pending = array_values(array_unique($projectIds));
        $summary = [
            'attempted' => count($pending),
            'migrated' => 0,
            'migratedWithWarning' => 0,
            'skippedNoOrchestrations' => 0,
            'skippedDisabled' => 0,
            'skippedJobRunning' => 0,
            'failed' => 0,
        ];
        /** @var array<int, InFlightJob> $inFlight */
        $inFlight = [];

        while ($pending !== [] || $inFlight !== []) {
            while (count($inFlight) < $this->concurrency && $pending !== []) {
                $submission = $this->submitProject(array_shift($pending), $force, $output);
                if ($submission instanceof ProjectResult) {
                    $this->recordResult($submission, $summary, $output, $onProjectFinished);
                    continue;
                }
                $inFlight[] = $submission;
            }

            if ($inFlight === []) {
                continue;
            }

            ($this->sleep)($this->pollIntervalSeconds);
            $inFlight = $this->pollInFlightJobs($inFlight, $summary, $output, $onProjectFinished);
        }

        return $summary;
    }

    /**
     * Runs the per-project pipeline up to job creation. Returns an in-flight slot on success,
     * or an immediately-final ProjectResult (skip or driver-side error).
     *
     * Order matters: the disabled/deleted check runs before any token is created, and the
     * configuration check runs before the queue guard so empty projects never appear in
     * customers' job history.
     *
     * @return ProjectResult|InFlightJob
     */
    private function submitProject(string $projectId, bool $force, OutputInterface $output)
    {
        try {
            $resolved = $this->checkProjectIsActive($projectId);
            if ($resolved !== null) {
                return $resolved;
            }

            $clients = $this->clientsFactory->createProjectClients($projectId);

            $resolved = $this->checkProjectNeedsMigration($projectId, $clients);
            if ($resolved !== null) {
                return $resolved;
            }

            $job = $clients->queueClient->createJob($this->buildMigrationJobData($force));
        } catch (Throwable $e) {
            return new ProjectResult(
                $projectId,
                null,
                ProjectResult::STATUS_ERROR,
                null,
                $e->getMessage()
            );
        }

        $this->writeLine($output, sprintf('Project %s: created job %s (%s)', $projectId, $job->id, $job->url));

        return [
            'projectId' => $projectId,
            'jobId' => $job->id,
            'queueClient' => $clients->queueClient,
            'startedAt' => microtime(true),
            'pollFailures' => 0,
        ];
    }

    /**
     * Runs before any ephemeral token is created. A deleted project (Manage API 404) is reported
     * together with disabled ones - the migration has nothing to do in either case. Any other
     * failure is rethrown so the caller turns it into a per-project error result.
     *
     * @return ProjectResult|null non-null when the project must not be migrated
     */
    private function checkProjectIsActive(string $projectId): ?ProjectResult
    {
        try {
            $project = $this->clientsFactory->getProject($projectId);
        } catch (ManageClientException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }

            return new ProjectResult(
                $projectId,
                null,
                ProjectResult::STATUS_SKIPPED_DISABLED,
                null,
                'project is deleted'
            );
        }

        if (isset($project['isDisabled']) && $project['isDisabled']) {
            return new ProjectResult(
                $projectId,
                null,
                ProjectResult::STATUS_SKIPPED_DISABLED,
                null,
                'project is disabled'
            );
        }

        return null;
    }

    /**
     * @return ProjectResult|null non-null when no new migration job should be created
     */
    private function checkProjectNeedsMigration(
        string $projectId,
        ProjectClients $clients
    ): ?ProjectResult {
        $configurations = $clients->components->listComponentConfigurations(
            (new ListComponentConfigurationsOptions())
                ->setComponentId(self::ORCHESTRATOR_COMPONENT_ID)
                ->setIsDeleted(false)
        );
        if (count($configurations) === 0) {
            return new ProjectResult(
                $projectId,
                null,
                ProjectResult::STATUS_SKIPPED_NO_ORCHESTRATIONS,
                null,
                'no keboola.orchestrator configurations'
            );
        }

        $liveJobs = $clients->queueClient->listJobs(
            (new ListJobsOptions())
                ->setComponents([self::MIGRATION_COMPONENT_ID])
                ->setStatuses(self::LIVE_JOB_STATUSES)
                ->setLimit(1)
        );
        if ($liveJobs !== []) {
            return new ProjectResult(
                $projectId,
                null,
                ProjectResult::STATUS_SKIPPED_JOB_RUNNING,
                null,
                'a keboola.flow-migration-tool job is already running in this project'
            );
        }

        return null;
    }

    /**
     * The migration is requested through configData so no stored configuration is left behind
     * in the customer's project. orchestrationIds and skipBroken are required by the component's
     * config definition in "project" mode.
     */
    private function buildMigrationJobData(bool $force): JobData
    {
        return new JobData(
            self::MIGRATION_COMPONENT_ID,
            null,
            [
                'parameters' => [
                    'mode' => 'project',
                    'orchestrationIds' => [],
                    'skipBroken' => true,
                    'dryRun' => !$force,
                ],
            ]
        );
    }

    /**
     * Polls every in-flight job once and returns the slots that are still running.
     *
     * @param array<int, InFlightJob> $inFlight
     * @param BatchSummary $summary
     * @param callable(ProjectResult): void $onProjectFinished
     * @return array<int, InFlightJob>
     */
    private function pollInFlightJobs(
        array $inFlight,
        array &$summary,
        OutputInterface $output,
        callable $onProjectFinished
    ): array {
        $stillRunning = [];
        foreach ($inFlight as $slot) {
            $polled = $this->pollOneJob($slot, $summary, $output, $onProjectFinished);
            if ($polled !== null) {
                $stillRunning[] = $polled;
            }
        }

        return $stillRunning;
    }

    /**
     * @param InFlightJob $slot
     * @param BatchSummary $summary
     * @param callable(ProjectResult): void $onProjectFinished
     * @return InFlightJob|null null once the project is resolved and its slot is freed
     */
    private function pollOneJob(
        array $slot,
        array &$summary,
        OutputInterface $output,
        callable $onProjectFinished
    ): ?array {
        try {
            $job = $slot['queueClient']->getJob($slot['jobId']);
        } catch (Throwable $e) {
            return $this->handlePollFailure($slot, $e, $summary, $output, $onProjectFinished);
        }

        $slot['pollFailures'] = 0;

        if (!$job->isFinished) {
            return $slot;
        }

        $this->recordResult($this->buildFinishedJobResult($slot, $job), $summary, $output, $onProjectFinished);

        return null;
    }

    /**
     * Keeps the slot in flight until MAX_CONSECUTIVE_POLL_FAILURES is reached, then resolves the
     * project as failed while preserving the job id - the job itself may still finish server-side.
     *
     * @param InFlightJob $slot
     * @param BatchSummary $summary
     * @param callable(ProjectResult): void $onProjectFinished
     * @return InFlightJob|null
     */
    private function handlePollFailure(
        array $slot,
        Throwable $error,
        array &$summary,
        OutputInterface $output,
        callable $onProjectFinished
    ): ?array {
        $slot['pollFailures']++;
        $this->writeLine($output, sprintf(
            'Project %s: polling job %s failed (%d/%d): %s',
            $slot['projectId'],
            $slot['jobId'],
            $slot['pollFailures'],
            self::MAX_CONSECUTIVE_POLL_FAILURES,
            $error->getMessage()
        ));

        if ($slot['pollFailures'] < self::MAX_CONSECUTIVE_POLL_FAILURES) {
            return $slot;
        }

        $this->recordResult(
            new ProjectResult(
                $slot['projectId'],
                $slot['jobId'],
                ProjectResult::STATUS_ERROR,
                null,
                sprintf(
                    'polling gave up after %d consecutive failures, job may still be running: %s',
                    self::MAX_CONSECUTIVE_POLL_FAILURES,
                    $error->getMessage()
                )
            ),
            $summary,
            $output,
            $onProjectFinished
        );

        return null;
    }

    /**
     * @param InFlightJob $slot
     */
    private function buildFinishedJobResult(array $slot, Job $job): ProjectResult
    {
        return new ProjectResult(
            $slot['projectId'],
            $slot['jobId'],
            $job->status,
            $job->durationSeconds ?? (int) round(microtime(true) - $slot['startedAt']),
            $this->extractJobError($job)
        );
    }

    private function extractJobError(Job $job): ?string
    {
        if ($job->isSuccess()) {
            return null;
        }
        $result = $job->result;
        if (is_array($result) && isset($result['message']) && is_scalar($result['message'])) {
            return (string) $result['message'];
        }

        return null;
    }

    /**
     * @param BatchSummary $summary
     * @param callable(ProjectResult): void $onProjectFinished
     */
    private function recordResult(
        ProjectResult $result,
        array &$summary,
        OutputInterface $output,
        callable $onProjectFinished
    ): void {
        $summary[$this->summaryKeyFor($result)]++;

        $this->writeLine($output, sprintf(
            'Project %s: %s%s%s',
            $result->projectId,
            $result->status,
            $result->durationSeconds !== null ? sprintf(' in %d s', $result->durationSeconds) : '',
            $result->error !== null ? sprintf(' (%s)', $result->error) : ''
        ));

        $onProjectFinished($result);
    }

    /**
     * @return key-of<BatchSummary>
     */
    private function summaryKeyFor(ProjectResult $result): string
    {
        return match ($result->status) {
            JobStatuses::SUCCESS->value => 'migrated',
            JobStatuses::WARNING->value => 'migratedWithWarning',
            ProjectResult::STATUS_SKIPPED_NO_ORCHESTRATIONS => 'skippedNoOrchestrations',
            ProjectResult::STATUS_SKIPPED_DISABLED => 'skippedDisabled',
            ProjectResult::STATUS_SKIPPED_JOB_RUNNING => 'skippedJobRunning',
            default => 'failed',
        };
    }

    private function writeLine(OutputInterface $output, string $message): void
    {
        $output->writeln(sprintf('[%s] %s', date('H:i:s'), $message));
    }
}
