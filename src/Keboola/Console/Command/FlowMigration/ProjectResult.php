<?php

declare(strict_types=1);

namespace Keboola\Console\Command\FlowMigration;

use Keboola\JobQueueClient\JobStatuses;

/**
 * Terminal outcome of one project in the flow-migration batch: either a finished
 * keboola.flow-migration-tool job (status = job terminal status), a skip, or a driver-side error.
 */
class ProjectResult
{
    public const STATUS_SKIPPED_DISABLED = 'skipped-disabled';
    public const STATUS_SKIPPED_NO_ORCHESTRATIONS = 'skipped-no-orchestrations';
    public const STATUS_SKIPPED_JOB_RUNNING = 'skipped-job-running';
    public const STATUS_ERROR = 'error';

    public string $projectId;
    public ?string $jobId;
    public string $status;
    public ?int $durationSeconds;
    public ?string $error;

    public function __construct(
        string $projectId,
        ?string $jobId,
        string $status,
        ?int $durationSeconds,
        ?string $error
    ) {
        $this->projectId = $projectId;
        $this->jobId = $jobId;
        $this->status = $status;
        $this->durationSeconds = $durationSeconds;
        $this->error = $error;
    }

    public function isSkipped(): bool
    {
        return in_array($this->status, [
            self::STATUS_SKIPPED_DISABLED,
            self::STATUS_SKIPPED_NO_ORCHESTRATIONS,
            self::STATUS_SKIPPED_JOB_RUNNING,
        ], true);
    }

    public function isFailed(): bool
    {
        // Anything that is not a skip and not a successful terminal job status is a failure -
        // unexpected statuses fail loud rather than passing silently.
        return !$this->isSkipped()
            && !in_array($this->status, [JobStatuses::SUCCESS->value, JobStatuses::WARNING->value], true);
    }
}
