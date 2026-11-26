<?php

namespace Keboola\Console\Tests;

use Keboola\Console\Command\SchedulerClient;
use Keboola\Console\Command\SchedulerClientException;

/**
 * Models the live Scheduler rows. A row exists for every scheduler configuration found in Storage when the
 * client is created, and a refresh copies target and schedule from Storage into the row, like the real service.
 */
class FakeSchedulerClient implements SchedulerClient
{
    /** @var array<int, string> */
    public array $refreshed = [];

    /** @var array<int, string> schedule configuration ids whose refresh must fail */
    public array $failRefreshFor = [];

    /** @var array<string, array<string, mixed>> schedule configuration id => live row */
    public array $rows = [];

    public int $listCalls = 0;

    private StatefulFakeComponents $components;

    public function __construct(StatefulFakeComponents $components)
    {
        $this->components = $components;
        foreach ($components->configs['keboola.scheduler'] ?? [] as $id => $config) {
            $this->rows[(string) $id] = $this->rowFromConfiguration((string) $id, $config);
        }
    }

    public function listSchedulesByTarget(string $componentId, string $configurationId): array
    {
        $this->listCalls++;

        return array_values(array_filter(
            $this->rows,
            fn (array $row) => $row['target']['componentId'] === $componentId
                && $row['target']['configurationId'] === $configurationId
        ));
    }

    public function refreshSchedule(string $configurationId): void
    {
        if (in_array($configurationId, $this->failRefreshFor, true)) {
            throw new SchedulerClientException('Scheduler is unavailable');
        }
        $this->refreshed[] = $configurationId;
        if (isset($this->rows[$configurationId])) {
            $this->rows[$configurationId] = $this->rowFromConfiguration(
                $configurationId,
                $this->components->configs['keboola.scheduler'][$configurationId]
            );
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function rowFromConfiguration(string $id, array $config): array
    {
        $schedule = $config['configuration']['schedule'];

        return [
            'configurationId' => $id,
            'schedule' => [
                'cronTab' => $schedule['cronTab'],
                'timezone' => $schedule['timezone'],
                'state' => $schedule['state'] ?? 'enabled',
            ],
            'target' => $config['configuration']['target'],
        ];
    }
}
