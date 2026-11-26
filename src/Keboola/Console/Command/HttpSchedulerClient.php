<?php

namespace Keboola\Console\Command;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;

class HttpSchedulerClient implements SchedulerClient
{
    private GuzzleClient $client;

    public function __construct(string $schedulerUrl, string $storageToken)
    {
        $this->client = new GuzzleClient([
            'base_uri' => $schedulerUrl,
            'headers' => ['X-StorageApi-Token' => $storageToken],
            'timeout' => 60,
        ]);
    }

    public static function schedulerUrlFromConnectionUrl(string $connectionUrl): ?string
    {
        $schedulerUrl = preg_replace('~^(https?://)connection\.~i', '$1scheduler.', rtrim($connectionUrl, '/'));

        return $schedulerUrl !== null && $schedulerUrl !== rtrim($connectionUrl, '/') ? $schedulerUrl : null;
    }

    public function refreshSchedule(string $configurationId): void
    {
        try {
            $this->client->post('/schedules/migrate', [
                'json' => [
                    'configurationId' => $configurationId,
                    'sourceConfigurationId' => $configurationId,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new SchedulerClientException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
