<?php

declare(strict_types=1);

namespace Keboola\Console\Tests\FlowMigration;

use Keboola\Console\Command\FlowMigration\ProjectClients;
use Keboola\Console\Command\FlowMigration\ProjectClientsFactory;
use RuntimeException;
use Throwable;

/**
 * In-memory double for ProjectClientsFactory. Deliberately does not call
 * parent::__construct() so no Manage API client is needed - same trick as FakeComponents.
 */
class FakeProjectClientsFactory extends ProjectClientsFactory
{
    /** @var array<int, string> projectIds passed to createProjectClients() */
    public array $createClientsCalls = [];

    /** @var array<string, array<mixed>|Throwable> */
    private array $projects;

    /** @var array<string, ProjectClients|Throwable> */
    private array $projectClients;

    /**
     * @param array<string, array<mixed>|Throwable> $projects projectId => project detail, or Throwable to throw
     * @param array<string, ProjectClients|Throwable> $projectClients projectId => clients, or Throwable
     */
    public function __construct(array $projects, array $projectClients = [])
    {
        $this->projects = $projects;
        $this->projectClients = $projectClients;
    }

    public function getProject(string $projectId): array
    {
        if (!array_key_exists($projectId, $this->projects)) {
            throw new RuntimeException(
                sprintf('FakeProjectClientsFactory: unknown project "%s"', $projectId)
            );
        }
        $project = $this->projects[$projectId];
        if ($project instanceof Throwable) {
            throw $project;
        }

        return $project;
    }

    public function createProjectClients(string $projectId): ProjectClients
    {
        $this->createClientsCalls[] = $projectId;
        if (!array_key_exists($projectId, $this->projectClients)) {
            throw new RuntimeException(
                sprintf('FakeProjectClientsFactory: no clients for project "%s"', $projectId)
            );
        }
        $clients = $this->projectClients[$projectId];
        if ($clients instanceof Throwable) {
            throw $clients;
        }

        return $clients;
    }
}
