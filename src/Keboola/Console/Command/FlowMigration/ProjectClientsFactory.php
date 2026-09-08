<?php

declare(strict_types=1);

namespace Keboola\Console\Command\FlowMigration;

use Keboola\JobQueueClient\Client as JobQueueClient;
use Keboola\ManageApi\Client as ManageClient;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\Components;

/**
 * Creates per-project API clients for the flow-migration batch. Every project gets its own
 * ephemeral storage token; the token expires on its own, so there is no cleanup step.
 */
class ProjectClientsFactory
{
    private const TOKEN_DESCRIPTION = 'AJDA-3117 keboola.orchestrator to keboola.flow migration (batch driver)';

    // One hour. The job runs for minutes and, being started from configData, does not wait on
    // anything project-side - only the shared queue - so this covers the whole run with a wide
    // margin while keeping a fully privileged token short-lived.
    private const TOKEN_EXPIRES_IN_SECONDS = 3600;

    private ManageClient $manageClient;
    private string $connectionUrl;
    private string $queueApiUrl;

    public function __construct(ManageClient $manageClient, string $connectionUrl, string $queueApiUrl)
    {
        $this->manageClient = $manageClient;
        $this->connectionUrl = $connectionUrl;
        $this->queueApiUrl = $queueApiUrl;
    }

    /**
     * @return array<mixed> Manage API project detail
     */
    public function getProject(string $projectId): array
    {
        return $this->manageClient->getProject((int) $projectId);
    }

    public function createProjectClients(string $projectId): ProjectClients
    {
        // Full project rights on purpose. The migration writes configurations, tables, triggers
        // (with a runWithTokenId copied from the source trigger, i.e. another token) and
        // notification subscriptions, and a migration failing halfway through is worse than a
        // short-lived privileged token. Restricting this only risks the component missing something.
        $tokenInfo = $this->manageClient->createProjectStorageToken((int) $projectId, [
            'description' => self::TOKEN_DESCRIPTION,
            'expiresIn' => self::TOKEN_EXPIRES_IN_SECONDS,
            'canManageBuckets' => true,
            'canManageTokens' => true,
            'canReadAllFileUploads' => true,
            'canPurgeTrash' => true,
        ]);

        $token = $tokenInfo['token'];
        assert(is_string($token));

        $storageClient = new StorageClient([
            'url' => $this->connectionUrl,
            'token' => $token,
        ]);

        return new ProjectClients(
            new Components($storageClient),
            new JobQueueClient($this->queueApiUrl, $token)
        );
    }
}
