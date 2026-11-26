<?php

namespace Keboola\Console\Tests;

use Keboola\Console\Command\DataAppScheduleMigrator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class DataAppScheduleMigratorTest extends TestCase
{
    private const EMPTY_RESULT = [
        'schedulesScanned' => 0,
        'schedulesMigrated' => 0,
        'schedulesAlreadyMigrated' => 0,
        'flowsCreated' => 0,
        'schedulesSkippedUnsupported' => 0,
        'schedulesSkippedUnresolvable' => 0,
        'schedulesFailed' => 0,
    ];

    /**
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private function schedule(string $id, array $target = [], string $state = 'enabled'): array
    {
        return [
            'id' => $id,
            'name' => 'Schedule ' . $id,
            'configuration' => [
                'schedule' => ['cronTab' => '0 6 * * *', 'timezone' => 'Europe/Prague', 'state' => $state],
                'target' => $target + [
                    'componentId' => 'keboola.data-apps',
                    'configurationId' => 'app-cfg-1',
                    'mode' => 'run',
                    'configurationRowIds' => [],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function dataAppsConfig(string $id, array $parameters = ['id' => '987']): array
    {
        return [
            'id' => $id,
            'name' => 'My app ' . $id,
            'configuration' => ['parameters' => $parameters],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $schedules
     * @param array<int, array<string, mixed>> $dataApps
     */
    private function components(array $schedules, array $dataApps = []): StatefulFakeComponents
    {
        return new StatefulFakeComponents([
            'keboola.scheduler' => $schedules,
            'keboola.data-apps' => $dataApps ?: [$this->dataAppsConfig('app-cfg-1')],
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function migrate(
        StatefulFakeComponents $components,
        FakeSchedulerClient $scheduler,
        bool $force,
        ?BufferedOutput $output = null
    ): array {
        return (new DataAppScheduleMigrator())->migrateProject(
            $components,
            $scheduler,
            $output ?? new BufferedOutput(),
            '500',
            $force
        );
    }

    public function testDryRunReportsWithoutWriting(): void
    {
        $components = $this->components([$this->schedule('s1')]);
        $scheduler = new FakeSchedulerClient();
        $output = new BufferedOutput();

        $result = $this->migrate($components, $scheduler, false, $output);

        $this->assertEquals(
            ['schedulesScanned' => 1, 'schedulesMigrated' => 1, 'flowsCreated' => 1] + self::EMPTY_RESULT,
            $result
        );
        $this->assertSame([], $components->createCalls);
        $this->assertSame([], $components->updateCalls);
        $this->assertSame([], $scheduler->refreshed);
        $this->assertStringContainsString('DRY-RUN: Migrating', $output->fetch());
    }

    public function testForceCreatesFlowRetargetsScheduleAndRefreshesIt(): void
    {
        $components = $this->components([$this->schedule('s1')]);
        $scheduler = new FakeSchedulerClient();
        $output = new BufferedOutput();

        $result = $this->migrate($components, $scheduler, true, $output);

        $this->assertEquals(
            ['schedulesScanned' => 1, 'schedulesMigrated' => 1, 'flowsCreated' => 1] + self::EMPTY_RESULT,
            $result
        );

        $this->assertCount(1, $components->createCalls);
        $flow = $components->createCalls[0];
        $this->assertSame('keboola.flow', $flow['componentId']);
        $this->assertStringContainsString('My app app-cfg-1', $flow['name']);
        $this->assertStringContainsString('[migrated-from-data-apps:app-cfg-1]', $flow['description']);
        $this->assertCount(1, $flow['configuration']['phases']);
        $this->assertCount(1, $flow['configuration']['tasks']);
        $task = $flow['configuration']['tasks'][0];
        $this->assertSame($flow['configuration']['phases'][0]['id'], $task['phase']);
        $this->assertTrue($task['enabled']);
        $this->assertSame(
            [
                'type' => 'job',
                'mode' => 'run',
                'componentId' => 'keboola.data-app-control',
                'configData' => ['parameters' => ['appId' => '987']],
            ],
            $task['task']
        );

        $this->assertCount(1, $components->updateCalls);
        $update = $components->updateCalls[0];
        $this->assertSame('keboola.scheduler', $update['componentId']);
        $this->assertSame('s1', $update['configurationId']);
        $this->assertSame(
            ['componentId' => 'keboola.flow', 'configurationId' => 'new-1', 'mode' => 'run', 'configurationRowIds' => []],
            $update['configuration']['target']
        );
        $this->assertSame(
            ['cronTab' => '0 6 * * *', 'timezone' => 'Europe/Prague', 'state' => 'enabled'],
            $update['configuration']['schedule']
        );
        $this->assertSame(['s1'], $scheduler->refreshed);

        $log = $output->fetch();
        $this->assertStringContainsString('FORCE: Migrating', $log);
        $this->assertStringContainsString(
            'ROLLBACK: project "500", schedule config "s1" ("Schedule s1"), old target {"componentId":"keboola.data-apps","configurationId":"app-cfg-1","mode":"run","configurationRowIds":[]}, new flow "new-1"',
            $log
        );
    }

    public function testSecondRunDoesNotCreateAnythingAgain(): void
    {
        $components = $this->components([$this->schedule('s1')]);
        $scheduler = new FakeSchedulerClient();
        $this->migrate($components, $scheduler, true);

        $result = $this->migrate($components, $scheduler, true);

        $this->assertEquals(
            ['schedulesScanned' => 1, 'schedulesAlreadyMigrated' => 1] + self::EMPTY_RESULT,
            $result
        );
        $this->assertCount(1, $components->createCalls);
        $this->assertCount(1, $components->updateCalls);
    }

    public function testSecondRunReRefreshesTheMigratedSchedule(): void
    {
        $components = $this->components([$this->schedule('s1')]);
        $scheduler = new FakeSchedulerClient();
        $this->migrate($components, $scheduler, true);
        $this->migrate($components, $scheduler, true);

        $this->assertSame(['s1', 's1'], $scheduler->refreshed);
    }

    public function testSecondDryRunDoesNotRefresh(): void
    {
        $components = $this->components([$this->schedule('s1')]);
        $scheduler = new FakeSchedulerClient();
        $this->migrate($components, $scheduler, true);
        $this->migrate($components, $scheduler, false);

        $this->assertSame(['s1'], $scheduler->refreshed);
    }

    public function testTwoSchedulesOfOneAppShareOneFlow(): void
    {
        $components = $this->components([$this->schedule('s1'), $this->schedule('s2')]);
        $scheduler = new FakeSchedulerClient();

        $result = $this->migrate($components, $scheduler, true);

        $this->assertEquals(
            ['schedulesScanned' => 2, 'schedulesMigrated' => 2, 'flowsCreated' => 1] + self::EMPTY_RESULT,
            $result
        );
        $this->assertCount(1, $components->createCalls);
        $this->assertCount(2, $components->updateCalls);
        $this->assertSame('new-1', $components->updateCalls[0]['configuration']['target']['configurationId']);
        $this->assertSame('new-1', $components->updateCalls[1]['configuration']['target']['configurationId']);
        $this->assertSame(['s1', 's2'], $scheduler->refreshed);
    }

    public function testDryRunCountsOneFlowForTwoSchedulesOfOneApp(): void
    {
        $components = $this->components([$this->schedule('s1'), $this->schedule('s2')]);

        $result = $this->migrate($components, new FakeSchedulerClient(), false);

        $this->assertSame(1, $result['flowsCreated']);
        $this->assertSame(2, $result['schedulesMigrated']);
    }

    public function testDataAppsConfigWithoutAppIdIsSkippedAsUnresolvable(): void
    {
        $components = $this->components(
            [$this->schedule('s1')],
            [$this->dataAppsConfig('app-cfg-1', ['task' => 'start'])]
        );
        $scheduler = new FakeSchedulerClient();
        $output = new BufferedOutput();

        $result = $this->migrate($components, $scheduler, true, $output);

        $this->assertEquals(
            ['schedulesScanned' => 1, 'schedulesSkippedUnresolvable' => 1] + self::EMPTY_RESULT,
            $result
        );
        $this->assertSame([], $components->createCalls);
        $this->assertSame([], $components->updateCalls);
        $this->assertSame([], $scheduler->refreshed);
        $this->assertStringContainsString('needs manual follow-up', $output->fetch());
    }

    public function testMissingDataAppsConfigIsSkippedAsUnresolvable(): void
    {
        $components = $this->components(
            [$this->schedule('s1', ['configurationId' => 'gone'])],
            [$this->dataAppsConfig('other')]
        );

        $result = $this->migrate($components, new FakeSchedulerClient(), true);

        $this->assertSame(1, $result['schedulesSkippedUnresolvable']);
        $this->assertSame([], $components->createCalls);
    }

    /**
     * @param array<string, mixed> $target
     */
    #[DataProvider('provideUnsupportedTargets')]
    public function testUnsupportedTargetsAreSkippedAndReported(array $target): void
    {
        $components = $this->components([$this->schedule('s1', $target)]);
        $output = new BufferedOutput();

        $result = $this->migrate($components, new FakeSchedulerClient(), true, $output);

        $this->assertEquals(
            ['schedulesScanned' => 1, 'schedulesSkippedUnsupported' => 1] + self::EMPTY_RESULT,
            $result
        );
        $this->assertSame([], $components->createCalls);
        $this->assertSame([], $components->updateCalls);
        $this->assertStringContainsString('schedule config "s1"', $output->fetch());
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function provideUnsupportedTargets(): iterable
    {
        yield 'with configuration rows' => [['configurationRowIds' => ['r1']]];
        yield 'with a tag' => [['tag' => '1.2.3']];
        yield 'with a mode other than run' => [['mode' => 'debug']];
    }

    public function testDataAppsConfigWithUnsupportedLegacyTaskIsSkippedAsUnsupported(): void
    {
        $components = $this->components(
            [$this->schedule('s1')],
            [$this->dataAppsConfig('app-cfg-1', ['id' => '987', 'task' => 'delete'])]
        );

        $result = $this->migrate($components, new FakeSchedulerClient(), true);

        $this->assertSame(1, $result['schedulesSkippedUnsupported']);
        $this->assertSame([], $components->createCalls);
    }

    public function testSchedulesOfOtherComponentsAreIgnored(): void
    {
        $components = $this->components([
            $this->schedule('s1', ['componentId' => 'keboola.orchestrator', 'configurationId' => 'o1']),
            $this->schedule('s2', ['componentId' => 'keboola.flow', 'configurationId' => 'unrelated-flow']),
        ]);
        $scheduler = new FakeSchedulerClient();

        $result = $this->migrate($components, $scheduler, true);

        $this->assertEquals(['schedulesScanned' => 2] + self::EMPTY_RESULT, $result);
        $this->assertSame([], $components->createCalls);
        $this->assertSame([], $scheduler->refreshed);
    }

    public function testFailedScheduleUpdateIsCountedAndRunContinuesWithNextSchedule(): void
    {
        $components = $this->components([
            $this->schedule('s1'),
            $this->schedule('s2', ['configurationId' => 'app-cfg-2']),
        ], [
            $this->dataAppsConfig('app-cfg-1', ['id' => '111']),
            $this->dataAppsConfig('app-cfg-2', ['id' => '222']),
        ]);
        $components->failUpdateFor = ['keboola.scheduler/s1'];
        $scheduler = new FakeSchedulerClient();
        $output = new BufferedOutput();

        $result = $this->migrate($components, $scheduler, true, $output);

        $this->assertEquals(
            ['schedulesScanned' => 2, 'schedulesMigrated' => 1, 'flowsCreated' => 2, 'schedulesFailed' => 1] + self::EMPTY_RESULT,
            $result
        );
        $this->assertSame(['s2'], $scheduler->refreshed);
        $this->assertSame('app-cfg-1', $components->configs['keboola.scheduler']['s1']['configuration']['target']['configurationId']);
        $this->assertStringContainsString('error while migrating schedule config "s1"', $output->fetch());
    }

    public function testRerunAfterFailedScheduleUpdateReusesTheFlowCreatedBefore(): void
    {
        $components = $this->components([$this->schedule('s1')]);
        $components->failUpdateFor = ['keboola.scheduler/s1'];
        $scheduler = new FakeSchedulerClient();
        $this->migrate($components, $scheduler, true);
        $components->failUpdateFor = [];

        $result = $this->migrate($components, $scheduler, true);

        $this->assertEquals(
            ['schedulesScanned' => 1, 'schedulesMigrated' => 1] + self::EMPTY_RESULT,
            $result
        );
        $this->assertCount(1, $components->createCalls);
        $this->assertSame('new-1', $components->configs['keboola.scheduler']['s1']['configuration']['target']['configurationId']);
    }

    public function testFailedRefreshIsCountedAndRetriedOnNextRun(): void
    {
        $components = $this->components([$this->schedule('s1')]);
        $scheduler = new FakeSchedulerClient();
        $scheduler->failRefreshFor = ['s1'];
        $output = new BufferedOutput();

        $first = $this->migrate($components, $scheduler, true, $output);

        $this->assertSame(1, $first['schedulesFailed']);
        $this->assertSame(0, $first['schedulesMigrated']);
        $this->assertSame('new-1', $components->configs['keboola.scheduler']['s1']['configuration']['target']['configurationId']);
        $this->assertStringContainsString('error while migrating schedule config "s1"', $output->fetch());

        $scheduler->failRefreshFor = [];
        $second = $this->migrate($components, $scheduler, true);

        $this->assertSame(1, $second['schedulesAlreadyMigrated']);
        $this->assertSame(['s1'], $scheduler->refreshed);
        $this->assertCount(1, $components->createCalls);
    }

    public function testDisabledScheduleKeepsItsState(): void
    {
        $components = $this->components([$this->schedule('s1', [], 'disabled')]);

        $this->migrate($components, new FakeSchedulerClient(), true);

        $this->assertSame('disabled', $components->updateCalls[0]['configuration']['schedule']['state']);
    }
}
