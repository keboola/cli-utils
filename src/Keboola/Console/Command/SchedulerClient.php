<?php

namespace Keboola\Console\Command;

interface SchedulerClient
{
    /**
     * Makes the Scheduler service re-read the given schedule configuration from Storage.
     *
     * @throws SchedulerClientException
     */
    public function refreshSchedule(string $configurationId): void;
}
