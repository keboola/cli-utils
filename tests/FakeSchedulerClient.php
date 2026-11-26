<?php

namespace Keboola\Console\Tests;

use Keboola\Console\Command\SchedulerClient;
use Keboola\Console\Command\SchedulerClientException;

class FakeSchedulerClient implements SchedulerClient
{
    /** @var array<int, string> */
    public array $refreshed = [];

    /** @var array<int, string> schedule configuration ids whose refresh must fail */
    public array $failRefreshFor = [];

    public function refreshSchedule(string $configurationId): void
    {
        if (in_array($configurationId, $this->failRefreshFor, true)) {
            throw new SchedulerClientException('Scheduler is unavailable');
        }
        $this->refreshed[] = $configurationId;
    }
}
