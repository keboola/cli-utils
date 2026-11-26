<?php

namespace Keboola\Console\Command;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException as GuzzleClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;

class HttpSchedulerClient implements SchedulerClient
{
    private GuzzleClient $client;

    public function __construct(string $schedulerUrl, string $storageToken, ?HandlerStack $handler = null)
    {
        $this->client = new GuzzleClient([
            'base_uri' => $schedulerUrl,
            'headers' => ['X-StorageApi-Token' => $storageToken],
            'timeout' => 60,
        ] + ($handler !== null ? ['handler' => $handler] : []));
    }

    public static function schedulerUrlFromConnectionUrl(string $connectionUrl): ?string
    {
        $schedulerUrl = preg_replace('~^(https?://)connection\.~i', '$1scheduler.', rtrim($connectionUrl, '/'));

        return $schedulerUrl !== null && $schedulerUrl !== rtrim($connectionUrl, '/') ? $schedulerUrl : null;
    }

    public function listSchedulesByTarget(string $componentId, string $configurationId): array
    {
        try {
            $response = $this->client->get('/schedules', [
                'query' => ['componentId' => $componentId, 'configurationId' => $configurationId],
            ]);
        } catch (GuzzleException $e) {
            throw new SchedulerClientException($e->getMessage(), (int) $e->getCode(), $e);
        }

        $rows = json_decode((string) $response->getBody(), true);
        if (!is_array($rows)) {
            throw new SchedulerClientException('Unexpected response of the Scheduler schedule list');
        }
        $result = [];
        foreach ($rows as $row) {
            $isValid = is_array($row)
                && is_scalar($row['configurationId'] ?? null)
                && is_array($row['schedule'] ?? null)
                && is_array($row['target'] ?? null);
            if (!$isValid) {
                throw new SchedulerClientException('Unexpected row in the Scheduler schedule list');
            }
            $result[] = [
                'configurationId' => (string) $row['configurationId'],
                'schedule' => $row['schedule'],
                'target' => $row['target'],
            ];
        }

        return $result;
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
        } catch (GuzzleClientException $e) {
            // A schedule configuration that was never activated has no Scheduler row to refresh and does not run.
            $isInactive = $e->getResponse()->getStatusCode() === 400
                && str_contains((string) $e->getResponse()->getBody(), 'no schedule found');
            if (!$isInactive) {
                throw new SchedulerClientException($e->getMessage(), (int) $e->getCode(), $e);
            }
        } catch (GuzzleException $e) {
            throw new SchedulerClientException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
