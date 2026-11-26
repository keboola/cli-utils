<?php

namespace Keboola\Console\Command;

interface SchedulerClient
{
    /**
     * Lists the live Scheduler rows that target the given configuration.
     *
     * @return array<int, array{configurationId: string, schedule: array<string, mixed>, target: array<string, mixed>}>
     * @throws SchedulerClientException
     */
    public function listSchedulesByTarget(string $componentId, string $configurationId): array;

    /**
     * Makes the Scheduler service re-read the given schedule configuration from Storage.
     *
     * @throws SchedulerClientException
     */
    public function refreshSchedule(string $configurationId): void;
}
