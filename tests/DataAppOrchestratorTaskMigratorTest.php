<?php

namespace Keboola\Console\Tests;

use Keboola\Console\Command\DataAppOrchestratorTaskMigrator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class DataAppOrchestratorTaskMigratorTest extends TestCase
{
    private function legacyTask(string $id, string $taskId, array $task): array
    {
        return [
            'id' => $taskId,
            'name' => $taskId,
            'phase' => 'p1',
            'enabled' => true,
            'task' => array_merge(['type' => 'job', 'componentId' => 'keboola.data-apps', 'mode' => 'run'], $task),
        ];
    }

    public function testMigratesInlineConfigDataStartTask(): void
    {
        $config = [
            'id' => 'c1',
            'name' => 'my flow',
            'configuration' => [
                'phases' => [['id' => 'p1', 'name' => 'Phase 1']],
                'tasks' => [
                    $this->legacyTask('c1', 't1', ['configData' => ['parameters' => ['id' => 'app-123']]]),
                ],
            ],
        ];
        $components = new FakeComponents(['keboola.flow' => [$config]]);
        $migrator = new DataAppOrchestratorTaskMigrator();

        $result = $migrator->migrateProject($components, new BufferedOutput(), '500', true);

        $this->assertSame(
            ['configsScanned' => 1, 'configsTouched' => 1, 'tasksMigrated' => 1, 'tasksSkippedUnsupported' => 0, 'tasksSkippedUnresolvable' => 0],
            $result
        );
        $this->assertCount(1, $components->updateCalls);
        $updated = $components->updateCalls[0];
        $this->assertSame('keboola.flow', $updated['componentId']);
        $this->assertSame('AJDA-2445: migrate keboola.data-apps orchestrator/flow tasks to keboola.data-app-control', $updated['changeDescription']);

        $updatedTask = $updated['configuration']['tasks'][0]['task'];
        $this->assertSame('keboola.data-app-control', $updatedTask['componentId']);
        $this->assertSame(['parameters' => ['appId' => 'app-123']], $updatedTask['configData']);
        $this->assertArrayNotHasKey('configId', $updatedTask);
        // Fields unrelated to componentId/configData must survive untouched (keboola.flow requires "type").
        $this->assertSame('job', $updatedTask['type']);
        $this->assertSame('run', $updatedTask['mode']);

        // phases must round-trip byte-for-byte.
        $this->assertSame($config['configuration']['phases'], $updated['configuration']['phases']);
    }

    public function testDryRunReportsButDoesNotCallUpdateConfiguration(): void
    {
        $config = [
            'id' => 'c1',
            'name' => 'my flow',
            'configuration' => [
                'phases' => [],
                'tasks' => [$this->legacyTask('c1', 't1', ['configData' => ['parameters' => ['id' => 'app-123']]])],
            ],
        ];
        $components = new FakeComponents(['keboola.flow' => [$config]]);
        $migrator = new DataAppOrchestratorTaskMigrator();

        $result = $migrator->migrateProject($components, new BufferedOutput(), '500', false);

        $this->assertSame(1, $result['tasksMigrated']);
        $this->assertCount(0, $components->updateCalls);
    }

    public function testSkipsDeliberatelyUnsupportedTaskVariant(): void
    {
        $config = [
            'id' => 'c1',
            'name' => 'my flow',
            'configuration' => [
                'phases' => [],
                'tasks' => [$this->legacyTask('c1', 't1', ['configData' => ['parameters' => ['id' => 'app-123', 'task' => 'delete']]])],
            ],
        ];
        $components = new FakeComponents(['keboola.flow' => [$config]]);
        $migrator = new DataAppOrchestratorTaskMigrator();

        $result = $migrator->migrateProject($components, new BufferedOutput(), '500', true);

        $this->assertSame(0, $result['tasksMigrated']);
        $this->assertSame(1, $result['tasksSkippedUnsupported']);
        $this->assertSame(0, $result['tasksSkippedUnresolvable']);
        $this->assertCount(0, $components->updateCalls);
    }

    public function testFlagsUnresolvableConfigIdReferenceSeparatelyAndCachesTheLookup(): void
    {
        $config = [
            'id' => 'c1',
            'name' => 'my flow',
            'configuration' => [
                'phases' => [],
                'tasks' => [
                    $this->legacyTask('c1', 't1', ['configId' => 'missing-config']),
                    $this->legacyTask('c1', 't2', ['configId' => 'missing-config']),
                ],
            ],
        ];
        $components = new FakeComponents(['keboola.flow' => [$config]]);
        $migrator = new DataAppOrchestratorTaskMigrator();

        $result = $migrator->migrateProject($components, new BufferedOutput(), '500', true);

        $this->assertSame(0, $result['tasksMigrated']);
        $this->assertSame(0, $result['tasksSkippedUnsupported']);
        $this->assertSame(2, $result['tasksSkippedUnresolvable']);
        // Same configId referenced by two tasks - the failed lookup must be cached, not repeated.
        $this->assertSame(1, $components->getConfigurationCalls);
    }

    public function testResolvesAppIdFromReferencedConfigId(): void
    {
        $config = [
            'id' => 'c1',
            'name' => 'my flow',
            'configuration' => [
                'phases' => [],
                'tasks' => [$this->legacyTask('c1', 't1', ['configId' => 'sibling-1'])],
            ],
        ];
        $sibling = ['configuration' => ['parameters' => ['id' => 'app-from-sibling']]];
        $components = new FakeComponents(
            ['keboola.flow' => [$config]],
            ['keboola.data-apps/sibling-1' => $sibling]
        );
        $migrator = new DataAppOrchestratorTaskMigrator();

        $result = $migrator->migrateProject($components, new BufferedOutput(), '500', true);

        $this->assertSame(1, $result['tasksMigrated']);
        $updatedTask = $components->updateCalls[0]['configuration']['tasks'][0]['task'];
        $this->assertSame(['parameters' => ['appId' => 'app-from-sibling']], $updatedTask['configData']);
    }

    public function testScansBothLegacyOrchestratorAndConditionalFlowComponents(): void
    {
        $orchestratorConfig = [
            'id' => 'o1',
            'name' => 'legacy orchestration',
            'configuration' => [
                'phases' => [],
                'tasks' => [$this->legacyTask('o1', 't1', ['configData' => ['parameters' => ['id' => 'app-o']]])],
            ],
        ];
        $flowConfig = [
            'id' => 'f1',
            'name' => 'conditional flow',
            'configuration' => [
                'phases' => [],
                'tasks' => [$this->legacyTask('f1', 't1', ['configData' => ['parameters' => ['id' => 'app-f']]])],
            ],
        ];
        $components = new FakeComponents([
            'keboola.orchestrator' => [$orchestratorConfig],
            'keboola.flow' => [$flowConfig],
        ]);
        $migrator = new DataAppOrchestratorTaskMigrator();

        $result = $migrator->migrateProject($components, new BufferedOutput(), '500', true);

        $this->assertSame(2, $result['configsScanned']);
        $this->assertSame(2, $result['tasksMigrated']);
        $this->assertCount(2, $components->updateCalls);
        $touchedComponentIds = array_column($components->updateCalls, 'componentId');
        sort($touchedComponentIds);
        $this->assertSame(['keboola.flow', 'keboola.orchestrator'], $touchedComponentIds);
    }

    public function testIsIdempotentOnAlreadyMigratedTasks(): void
    {
        $alreadyMigratedTask = [
            'id' => 't1',
            'name' => 't1',
            'phase' => 'p1',
            'enabled' => true,
            'task' => [
                'type' => 'job',
                'componentId' => 'keboola.data-app-control',
                'configData' => ['parameters' => ['appId' => 'app-123']],
                'mode' => 'run',
            ],
        ];
        $config = [
            'id' => 'c1',
            'name' => 'my flow',
            'configuration' => ['phases' => [], 'tasks' => [$alreadyMigratedTask]],
        ];
        $components = new FakeComponents(['keboola.flow' => [$config]]);
        $migrator = new DataAppOrchestratorTaskMigrator();

        $result = $migrator->migrateProject($components, new BufferedOutput(), '500', true);

        $this->assertSame(0, $result['tasksMigrated']);
        $this->assertSame(0, $result['configsTouched']);
        $this->assertCount(0, $components->updateCalls);
    }
}
