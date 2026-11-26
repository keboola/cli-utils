<?php

namespace Keboola\Console\Tests;

use Keboola\Console\Command\HttpSchedulerClient;
use Keboola\Console\Command\MigrateDataAppsOrchestratorTasks;
use Keboola\Console\Command\SchedulerClient;
use Keboola\ManageApi\Client;
use Keboola\StorageApi\Components;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class MigrateDataAppsSchedulesCommandTest extends TestCase
{
    /**
     * @param array<int, string> $features
     * @param array<int, array<string, mixed>> $tokenRequests
     */
    private function manageClient(array $features, array &$tokenRequests): Client
    {
        return new class (['url' => 'https://connection.keboola.com', 'token' => 'manage'], $features, $tokenRequests) extends Client {
            /** @var array<int, string> */
            private array $features;
            /** @var array<int, array<string, mixed>> */
            private array $tokenRequests;

            /**
             * @param array<string, mixed> $config
             * @param array<int, string> $features
             * @param array<int, array<string, mixed>> $tokenRequests
             */
            public function __construct(array $config, array $features, array &$tokenRequests)
            {
                parent::__construct($config);
                $this->features = $features;
                $this->tokenRequests = &$tokenRequests;
            }

            public function getProject(int $id): array
            {
                return ['id' => $id, 'features' => $this->features];
            }

            public function createProjectStorageToken(int $projectId, array $params): array
            {
                $this->tokenRequests[] = $params;

                return ['token' => 'project-token'];
            }
        };
    }

    /**
     * @param array<int, string> $features
     * @param array<int, array<string, mixed>> $tokenRequests
     */
    private function command(
        StatefulFakeComponents $components,
        FakeSchedulerClient $scheduler,
        array $features,
        array &$tokenRequests,
        array &$schedulerUrls
    ): MigrateDataAppsOrchestratorTasks {
        $manage = $this->manageClient($features, $tokenRequests);

        return new class ($manage, $components, $scheduler, $schedulerUrls) extends MigrateDataAppsOrchestratorTasks {
            /** @var array<int, string> */
            private array $schedulerUrls;

            /**
             * @param array<int, string> $schedulerUrls
             */
            public function __construct(
                private Client $manage,
                private StatefulFakeComponents $components,
                private FakeSchedulerClient $scheduler,
                array &$schedulerUrls
            ) {
                parent::__construct();
                $this->schedulerUrls = &$schedulerUrls;
            }

            protected function createManageClient(string $host, string $token): Client
            {
                return $this->manage;
            }

            protected function createComponents(string $url, string $token): Components
            {
                return $this->components;
            }

            protected function createSchedulerClient(string $schedulerUrl, string $token): SchedulerClient
            {
                $this->schedulerUrls[] = $schedulerUrl;

                return $this->scheduler;
            }
        };
    }

    private function components(): StatefulFakeComponents
    {
        return new StatefulFakeComponents([
            'keboola.scheduler' => [[
                'id' => 's1',
                'name' => 'Daily',
                'configuration' => [
                    'schedule' => ['cronTab' => '0 6 * * *', 'timezone' => 'UTC', 'state' => 'enabled'],
                    'target' => [
                        'componentId' => 'keboola.data-apps',
                        'configurationId' => 'app-cfg-1',
                        'mode' => 'run',
                        'configurationRowIds' => [],
                    ],
                ],
            ]],
            'keboola.data-apps' => [['id' => 'app-cfg-1', 'name' => 'App', 'configuration' => ['parameters' => ['id' => '987']]]],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(MigrateDataAppsOrchestratorTasks $command, array $input = [], string $url = 'https://connection.keboola.com'): CommandTester
    {
        $tester = new CommandTester($command);
        $tester->execute(['token' => 'manage', 'url' => $url, 'projects' => '500'] + $input);

        return $tester;
    }

    public function testForceMigratesSchedulesAndRequestsSchedulerAccess(): void
    {
        $components = $this->components();
        $scheduler = new FakeSchedulerClient();
        $tokenRequests = [];
        $schedulerUrls = [];

        $tester = $this->execute($this->command($components, $scheduler, [], $tokenRequests, $schedulerUrls), ['--force' => true]);

        $this->assertContains('keboola.scheduler', $tokenRequests[0]['componentAccess']);
        $this->assertSame(['https://scheduler.keboola.com'], $schedulerUrls);
        $this->assertSame(['s1'], $scheduler->refreshed);
        $this->assertStringContainsString("1 schedules migrated\n1 flows created", $tester->getDisplay());
    }

    public function testDryRunWritesNothingAndSaysWouldBeMigrated(): void
    {
        $components = $this->components();
        $scheduler = new FakeSchedulerClient();
        $tokenRequests = [];
        $schedulerUrls = [];

        $tester = $this->execute($this->command($components, $scheduler, [], $tokenRequests, $schedulerUrls));

        $this->assertSame([], $components->createCalls);
        $this->assertSame([], $scheduler->refreshed);
        $this->assertStringContainsString('1 schedules would be migrated in force mode', $tester->getDisplay());
    }

    public function testSkipSchedulesOptionLeavesSchedulesAlone(): void
    {
        $components = $this->components();
        $scheduler = new FakeSchedulerClient();
        $tokenRequests = [];
        $schedulerUrls = [];

        $tester = $this->execute(
            $this->command($components, $scheduler, [], $tokenRequests, $schedulerUrls),
            ['--force' => true, '--skip-schedules' => true]
        );

        $this->assertNotContains('keboola.scheduler', $tokenRequests[0]['componentAccess']);
        $this->assertSame([], $components->createCalls);
        $this->assertSame([], $components->updateCalls);
        $this->assertSame([], $scheduler->refreshed);
        $this->assertStringNotContainsString('schedules', $tester->getDisplay());
    }

    public function testProjectWithProtectedDefaultBranchIsSkippedForSchedules(): void
    {
        $components = $this->components();
        $scheduler = new FakeSchedulerClient();
        $tokenRequests = [];
        $schedulerUrls = [];

        $tester = $this->execute(
            $this->command($components, $scheduler, ['protected-default-branch'], $tokenRequests, $schedulerUrls),
            ['--force' => true]
        );

        $this->assertSame([], $components->createCalls);
        $this->assertSame([], $scheduler->refreshed);
        $this->assertStringContainsString('1 projects skipped for schedules (protected default branch)', $tester->getDisplay());
    }

    public function testUrlWithoutConnectionHostIsReportedAsProjectError(): void
    {
        $components = $this->components();
        $tokenRequests = [];
        $schedulerUrls = [];

        $tester = $this->execute(
            $this->command($components, new FakeSchedulerClient(), [], $tokenRequests, $schedulerUrls),
            ['--force' => true],
            'https://example.com'
        );

        $this->assertSame([], $components->createCalls);
        $this->assertStringContainsString('cannot derive the Scheduler URL', $tester->getDisplay());
        $this->assertStringContainsString('1 projects had errors', $tester->getDisplay());
    }

    #[DataProvider('provideConnectionUrls')]
    public function testSchedulerUrlIsDerivedFromConnectionUrl(string $connectionUrl, ?string $expected): void
    {
        $this->assertSame($expected, HttpSchedulerClient::schedulerUrlFromConnectionUrl($connectionUrl));
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function provideConnectionUrls(): iterable
    {
        yield 'multi-tenant stack' => ['https://connection.keboola.com', 'https://scheduler.keboola.com'];
        yield 'trailing slash' => ['https://connection.eu-central-1.keboola.com/', 'https://scheduler.eu-central-1.keboola.com'];
        yield 'single-tenant stack' => ['https://connection.north-europe.azure.keboola.com', 'https://scheduler.north-europe.azure.keboola.com'];
        yield 'not a connection host' => ['https://example.com', null];
    }
}
