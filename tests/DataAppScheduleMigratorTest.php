<?php

namespace Keboola\Console\Tests;

use Keboola\Console\Command\DataAppScheduleMigrator;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
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
        'flowsCreatedDisabled' => 0,
        'schedulesSkippedUnsupported' => 0,
        'schedulesSkippedUnresolvable' => 0,
        'schedulesSkippedDiverged' => 0,
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
        $scheduler = new FakeSchedulerClient($components);
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
        $scheduler = new FakeSchedulerClient($components);
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
            'ROLLBACK: project "500", schedule config "s1" ("Schedule s1"), old target {"componentId":"keboola.data-apps","configurationId":"app-cfg-1","mode":"run","configurationRowIds":[]}, live row {"cronTab":"0 6 * * *","timezone":"Europe\\/Prague","state":"enabled"}, new flow "new-1"',
            $log
        );
    }

    public function testSecondRunDoesNotCreateAnythingAgain(): void
    {
        $components = $this->components([$this->schedule('s1')]);
        $scheduler = new FakeSchedulerClient($components);
        $this->migrate($components, $scheduler, true);

        $result = $this->migrate($components, $scheduler, true);

        $this->assertEquals(
            ['schedulesScanned' => 1, 'schedulesAlreadyMigrated' => 1] + self::EMPTY_RESULT,
            $result
        );
        $this->assertCount(1, $components->createCalls);
        $this->assertCount(1, $components->updateCalls);
    }

    public function testSecondRunDoesNotWriteToTheScheduler(): void
    {
        $components = $this->components([$this->schedule('s1')]);
        $scheduler = new FakeSchedulerClient($components);
        $this->migrate($components, $scheduler, true);
        $this->migrate($components, $scheduler, true);

        $this->assertSame(['s1'], $scheduler->refreshed);
    }

    public function testSecondDryRunDoesNotRefresh(): void
    {
        $components = $this->components([$this->schedule('s1')]);
        $scheduler = new FakeSchedulerClient($components);
        $this->migrate($components, $scheduler, true);
        $this->migrate($components, $scheduler, false);

        $this->assertSame(['s1'], $scheduler->refreshed);
    }

    public function testTwoSchedulesOfOneAppShareOneFlow(): void
    {
        $components = $this->components([$this->schedule('s1'), $this->schedule('s2')]);
        $scheduler = new FakeSchedulerClient($components);

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

        $result = $this->migrate($components, new FakeSchedulerClient($components), false);

        $this->assertSame(1, $result['flowsCreated']);
        $this->assertSame(2, $result['schedulesMigrated']);
    }

    public function testDataAppsConfigWithoutAppIdIsSkippedAsUnresolvable(): void
    {
        $components = $this->components(
            [$this->schedule('s1')],
            [$this->dataAppsConfig('app-cfg-1', ['task' => 'start'])]
        );
        $scheduler = new FakeSchedulerClient($components);
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

        $result = $this->migrate($components, new FakeSchedulerClient($components), true);

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

        $result = $this->migrate($components, new FakeSchedulerClient($components), true, $output);

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

        $result = $this->migrate($components, new FakeSchedulerClient($components), true);

        $this->assertSame(1, $result['schedulesSkippedUnsupported']);
        $this->assertSame([], $components->createCalls);
    }

    public function testSchedulesOfOtherComponentsAreIgnored(): void
    {
        $components = $this->components([
            $this->schedule('s1', ['componentId' => 'keboola.orchestrator', 'configurationId' => 'o1']),
            $this->schedule('s2', ['componentId' => 'keboola.flow', 'configurationId' => 'unrelated-flow']),
        ]);
        $scheduler = new FakeSchedulerClient($components);

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
        $scheduler = new FakeSchedulerClient($components);
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
        $scheduler = new FakeSchedulerClient($components);
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
        $scheduler = new FakeSchedulerClient($components);
        $scheduler->failRefreshFor = ['s1'];
        $output = new BufferedOutput();

        $first = $this->migrate($components, $scheduler, true, $output);

        $this->assertSame(1, $first['schedulesFailed']);
        $this->assertSame(0, $first['schedulesMigrated']);
        $this->assertSame('new-1', $components->configs['keboola.scheduler']['s1']['configuration']['target']['configurationId']);
        $this->assertStringContainsString('error while migrating schedule config "s1"', $output->fetch());

        $scheduler->failRefreshFor = [];
        $this->assertSame('keboola.data-apps', $scheduler->rows['s1']['target']['componentId']);
        $second = $this->migrate($components, $scheduler, true);

        $this->assertSame(1, $second['schedulesAlreadyMigrated']);
        $this->assertSame(['s1'], $scheduler->refreshed);
        $this->assertSame('keboola.flow', $scheduler->rows['s1']['target']['componentId']);
        $this->assertCount(1, $components->createCalls);
    }

    public function testDisabledScheduleKeepsItsState(): void
    {
        $components = $this->components([$this->schedule('s1', [], 'disabled')]);

        $this->migrate($components, new FakeSchedulerClient($components), true);

        $this->assertSame('disabled', $components->updateCalls[0]['configuration']['schedule']['state']);
    }

    public function testFailedFlowCreationIsNotCountedAsCreated(): void
    {
        $components = $this->components([$this->schedule('s1')]);
        $components->failCreate = true;

        $result = $this->migrate($components, new FakeSchedulerClient($components), true);

        $this->assertEquals(
            ['schedulesScanned' => 1, 'schedulesFailed' => 1] + self::EMPTY_RESULT,
            $result
        );
        $this->assertSame([], $components->updateCalls);
    }

    public function testFailedRepairRefreshOfAlreadyMigratedScheduleIsCountedOnlyAsFailed(): void
    {
        $components = $this->components([$this->schedule('s1')]);
        $scheduler = new FakeSchedulerClient($components);
        $scheduler->failRefreshFor = ['s1'];
        $this->migrate($components, $scheduler, true);

        $result = $this->migrate($components, $scheduler, true);

        $this->assertEquals(
            ['schedulesScanned' => 1, 'schedulesFailed' => 1] + self::EMPTY_RESULT,
            $result
        );
    }

    public function testTransportErrorOfOneScheduleDoesNotAbortTheRun(): void
    {
        $components = $this->components([$this->schedule('s1')]);
        $components->failCreateWith = new ConnectException('Connection refused', new Request('POST', 'https://connection.keboola.com'));

        $result = $this->migrate($components, new FakeSchedulerClient($components), true);

        $this->assertSame(1, $result['schedulesFailed']);
    }

    public function testDisabledDataAppsConfigIsMigratedToADisabledFlow(): void
    {
        $components = $this->components(
            [$this->schedule('s1')],
            [['isDisabled' => true] + $this->dataAppsConfig('app-cfg-1')]
        );
        $output = new BufferedOutput();

        $result = $this->migrate($components, new FakeSchedulerClient($components), true, $output);

        $this->assertEquals(
            ['schedulesScanned' => 1, 'schedulesMigrated' => 1, 'flowsCreated' => 1, 'flowsCreatedDisabled' => 1] + self::EMPTY_RESULT,
            $result
        );
        $this->assertTrue($components->createCalls[0]['isDisabled']);
        $this->assertStringContainsString('source configuration is disabled', $output->fetch());
    }

    public function testEnabledDataAppsConfigIsMigratedToAnEnabledFlow(): void
    {
        $components = $this->components([$this->schedule('s1')]);

        $this->migrate($components, new FakeSchedulerClient($components), true);

        $this->assertFalse($components->createCalls[0]['isDisabled']);
    }

    public function testDryRunCountsDisabledFlow(): void
    {
        $components = $this->components(
            [$this->schedule('s1')],
            [['isDisabled' => true] + $this->dataAppsConfig('app-cfg-1')]
        );

        $result = $this->migrate($components, new FakeSchedulerClient($components), false);

        $this->assertSame(1, $result['flowsCreatedDisabled']);
        $this->assertSame([], $components->createCalls);
    }

    /**
     * @param array<string, string> $liveSchedule
     */
    #[DataProvider('provideDivergedLiveRows')]
    public function testLiveRowThatDiffersFromTheConfigurationIsSkippedAndReported(array $liveSchedule): void
    {
        $components = $this->components([$this->schedule('s1')]);
        $scheduler = new FakeSchedulerClient($components);
        $scheduler->rows['s1']['schedule'] = $liveSchedule + $scheduler->rows['s1']['schedule'];
        $output = new BufferedOutput();

        $result = $this->migrate($components, $scheduler, true, $output);

        $this->assertEquals(
            ['schedulesScanned' => 1, 'schedulesSkippedDiverged' => 1] + self::EMPTY_RESULT,
            $result
        );
        $this->assertSame([], $components->createCalls);
        $this->assertSame([], $components->updateCalls);
        $this->assertSame([], $scheduler->refreshed);
        $log = $output->fetch();
        $this->assertStringContainsString('needs manual follow-up', $log);
        $this->assertStringContainsString('live row', $log);
    }

    /**
     * @return iterable<string, array{0: array<string, string>}>
     */
    public static function provideDivergedLiveRows(): iterable
    {
        yield 'state' => [['state' => 'disabled']];
        yield 'cron' => [['cronTab' => '0 7 * * *']];
        yield 'timezone' => [['timezone' => 'UTC']];
    }

    public function testConfigurationWithoutStateMatchesAnEnabledLiveRow(): void
    {
        $schedule = $this->schedule('s1');
        unset($schedule['configuration']['schedule']['state']);
        $components = $this->components([$schedule]);

        $result = $this->migrate($components, new FakeSchedulerClient($components), true);

        $this->assertSame(1, $result['schedulesMigrated']);
    }

    public function testScheduleWithoutLiveRowIsRetargetedWithoutRefreshCall(): void
    {
        $components = $this->components([$this->schedule('s1')]);
        $scheduler = new FakeSchedulerClient($components);
        $scheduler->rows = [];

        $result = $this->migrate($components, $scheduler, true);

        $this->assertEquals(
            ['schedulesScanned' => 1, 'schedulesMigrated' => 1, 'flowsCreated' => 1] + self::EMPTY_RESULT,
            $result
        );
        $this->assertCount(1, $components->updateCalls);
        $this->assertSame([], $scheduler->refreshed);
    }

    public function testRollbackLineContainsTheLiveState(): void
    {
        $components = $this->components([$this->schedule('s1')]);
        $output = new BufferedOutput();

        $this->migrate($components, new FakeSchedulerClient($components), true, $output);

        $this->assertStringContainsString(
            'live row {"cronTab":"0 6 * * *","timezone":"Europe\\/Prague","state":"enabled"}',
            $output->fetch()
        );
    }

    public function testOnlyTheRowOfTheSameScheduleIsCompared(): void
    {
        $components = $this->components([$this->schedule('s1'), $this->schedule('s2')]);
        $scheduler = new FakeSchedulerClient($components);
        $scheduler->rows['s2']['schedule']['state'] = 'disabled';

        $result = $this->migrate($components, $scheduler, true);

        $this->assertSame(1, $result['schedulesMigrated']);
        $this->assertSame(1, $result['schedulesSkippedDiverged']);
        $this->assertSame(['s1'], $scheduler->refreshed);
    }

    public function testAlreadyMigratedScheduleWithDivergedLiveRowOnOldTargetIsNotRefreshed(): void
    {
        $components = $this->components([$this->schedule('s1')]);
        $scheduler = new FakeSchedulerClient($components);
        $scheduler->failRefreshFor = ['s1'];
        $this->migrate($components, $scheduler, true);
        $scheduler->failRefreshFor = [];
        $scheduler->rows['s1']['schedule']['state'] = 'disabled';

        $result = $this->migrate($components, $scheduler, true);

        $this->assertSame(1, $result['schedulesSkippedDiverged']);
        $this->assertSame([], $scheduler->refreshed);
    }

    public function testAlreadyMigratedScheduleThatCustomerAddedToTheFlowIsNotTouched(): void
    {
        $components = $this->components([$this->schedule('s1')]);
        $scheduler = new FakeSchedulerClient($components);
        $this->migrate($components, $scheduler, true);
        $scheduler->rows['s1']['schedule']['state'] = 'disabled';

        $result = $this->migrate($components, $scheduler, true);

        $this->assertSame(1, $result['schedulesAlreadyMigrated']);
        $this->assertSame(['s1'], $scheduler->refreshed);
    }

    public function testFlowCommittedByAFailedCreateIsReusedByTheNextScheduleOfTheSameApp(): void
    {
        $components = $this->components([$this->schedule('s1'), $this->schedule('s2')]);
        $components->commitThenFailCreate = true;

        $result = $this->migrate($components, new FakeSchedulerClient($components), true);

        $this->assertEquals(
            ['schedulesScanned' => 2, 'schedulesMigrated' => 1, 'schedulesFailed' => 1] + self::EMPTY_RESULT,
            $result
        );
        $this->assertCount(1, $components->configs['keboola.flow']);
        $this->assertSame('new-1', $components->configs['keboola.scheduler']['s2']['configuration']['target']['configurationId']);
    }

    public function testDuplicateMarkersWarnAndTheFirstFlowIsUsed(): void
    {
        $flow = static fn (string $id): array => [
            'id' => $id,
            'name' => 'copy ' . $id,
            'description' => 'x [migrated-from-data-apps:app-cfg-1]',
            'configuration' => [],
        ];
        $components = new StatefulFakeComponents([
            'keboola.scheduler' => [
                $this->schedule('s1'),
                $this->schedule('s2', ['componentId' => 'keboola.flow', 'configurationId' => 'f2']),
            ],
            'keboola.data-apps' => [$this->dataAppsConfig('app-cfg-1')],
            'keboola.flow' => [$flow('f1'), $flow('f2')],
        ]);
        $output = new BufferedOutput();

        $result = $this->migrate($components, new FakeSchedulerClient($components), true, $output);

        $this->assertEquals(
            ['schedulesScanned' => 2, 'schedulesMigrated' => 1, 'schedulesAlreadyMigrated' => 1] + self::EMPTY_RESULT,
            $result
        );
        $this->assertSame('f1', $components->configs['keboola.scheduler']['s1']['configuration']['target']['configurationId']);
        $this->assertStringContainsString('Warning: flows "f1" and "f2" both carry the marker', $output->fetch());
    }
}
