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

    // 12 hours: the job may wait in the queue and the runtime keeps using this token for the whole
    // run - an expiry mid-migration is worse than a longer-lived privileged token.
    private const TOKEN_EXPIRES_IN_SECONDS = 43200;

    // Broad rights on purpose: trigger and notification migration touches tables and project-level
    // resources, and a migration failing halfway through is worse than a short-lived privileged token.
    private const TOKEN_COMPONENT_ACCESS = [
        'keboola.orchestrator',
        'keboola.flow',
        'keboola.scheduler',
        'keboola.flow-migration-tool',
    ];

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
        return $this->manageClient->getProject($projectId);
    }

    public function createProjectClients(string $projectId): ProjectClients
    {
        $tokenInfo = $this->manageClient->createProjectStorageToken($projectId, [
            'description' => self::TOKEN_DESCRIPTION,
            'expiresIn' => self::TOKEN_EXPIRES_IN_SECONDS,
            'canManageBuckets' => true,
            'canReadAllFileUploads' => true,
            'componentAccess' => self::TOKEN_COMPONENT_ACCESS,
        ]);

        $storageClient = new StorageClient([
            'url' => $this->connectionUrl,
            'token' => $tokenInfo['token'],
        ]);

        return new ProjectClients(
            new Components($storageClient),
            new JobQueueClient($this->queueApiUrl, $tokenInfo['token'])
        );
    }
}
