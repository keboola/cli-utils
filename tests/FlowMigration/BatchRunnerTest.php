<?php

declare(strict_types=1);

namespace Keboola\Console\Tests\FlowMigration;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Keboola\Console\Command\FlowMigration\BatchRunner;
use Keboola\Console\Command\FlowMigration\ProjectClients;
use Keboola\Console\Command\FlowMigration\ProjectResult;
use Keboola\Console\Tests\FakeComponents;
use Keboola\ManageApi\ClientException as ManageClientException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

class BatchRunnerTest extends TestCase
{
    /** @var array<int, ProjectResult> */
    private array $results = [];

    /** @var array<int, int> */
    private array $sleeps = [];

    private function collector(): Closure
    {
        return function (ProjectResult $result): void {
            $this->results[] = $result;
        };
    }

    private function sleepRecorder(): Closure
    {
        return function (int $seconds): void {
            $this->sleeps[] = $seconds;
        };
    }

    /**
     * @return array<string, mixed> enabled project detail as returned by the Manage API
     */
    private static function enabledProject(string $id): array
    {
        return ['id' => $id, 'name' => 'Project ' . $id, 'isDisabled' => false];
    }

    private static function clientsWith(
        FakeJobQueueClient $queueClient,
        bool $hasOrchestrations = true
    ): ProjectClients {
        $configs = $hasOrchestrations
            ? ['keboola.orchestrator' => [['id' => 'orch-1', 'name' => 'Daily load', 'configuration' => []]]]
            : [];

        return new ProjectClients(new FakeComponents($configs), $queueClient);
    }

    public function testHappyPathCreatesJobAndReportsSuccess(): void
    {
        $queueClient = new FakeJobQueueClient(
            [FakeJobQueueClient::makeJob('job-1', 'created')],
            ['job-1' => [
                FakeJobQueueClient::makeJob('job-1', 'processing'),
                FakeJobQueueClient::makeJob('job-1', 'success', 42),
            ]]
        );
        $factory = new FakeProjectClientsFactory(
            ['100' => self::enabledProject('100')],
            ['100' => self::clientsWith($queueClient)]
        );
        $runner = new BatchRunner($factory, 10, 5, $this->sleepRecorder());

        $summary = $runner->run(['100'], true, new BufferedOutput(), $this->collector());

        // Exact job payload - this is the whole contract with keboola.flow-migration-tool.
        $this->assertCount(1, $queueClient->createdJobs);
        $this->assertSame('keboola.flow-migration-tool', $queueClient->createdJobs[0]['component']);
        $this->assertNull($queueClient->createdJobs[0]['config']);
        $this->assertSame('run', $queueClient->createdJobs[0]['mode']);
        $this->assertSame(
            ['parameters' => [
                'mode' => 'project',
                'orchestrationIds' => [],
                'skipBroken' => true,
                'dryRun' => false,
            ]],
            $queueClient->createdJobs[0]['configData']
        );
        // The live-job guard ran exactly once before submission.
        $this->assertSame(1, $queueClient->listJobsCalls);

        $this->assertCount(1, $this->results);
        $this->assertSame('100', $this->results[0]->projectId);
        $this->assertSame('job-1', $this->results[0]->jobId);
        $this->assertSame('success', $this->results[0]->status);
        $this->assertSame(42, $this->results[0]->durationSeconds);
        $this->assertNull($this->results[0]->error);

        // Two poll sweeps (processing, then success), each preceded by one poll-interval sleep.
        $this->assertSame([5, 5], $this->sleeps);

        $this->assertSame(1, $summary['attempted']);
        $this->assertSame(1, $summary['migrated']);
        $this->assertSame(0, $summary['failed']);
    }

    public function testWithoutForceJobRunsWithDryRunTrue(): void
    {
        $queueClient = new FakeJobQueueClient(
            [FakeJobQueueClient::makeJob('job-1', 'created')],
            ['job-1' => [FakeJobQueueClient::makeJob('job-1', 'success', 1)]]
        );
        $factory = new FakeProjectClientsFactory(
            ['100' => self::enabledProject('100')],
            ['100' => self::clientsWith($queueClient)]
        );
        $runner = new BatchRunner($factory, 10, 5, $this->sleepRecorder());

        $runner->run(['100'], false, new BufferedOutput(), $this->collector());

        $configData = $queueClient->createdJobs[0]['configData'];
        $this->assertIsArray($configData);
        $this->assertTrue($configData['parameters']['dryRun']);
    }

    public function testJobEndingInErrorMarksProjectFailedButBatchContinues(): void
    {
        $queueClient1 = new FakeJobQueueClient(
            [FakeJobQueueClient::makeJob('job-1', 'created')],
            ['job-1' => [FakeJobQueueClient::makeJob('job-1', 'error', 10, ['message' => 'boom'])]]
        );
        $queueClient2 = new FakeJobQueueClient(
            [FakeJobQueueClient::makeJob('job-2', 'created')],
            ['job-2' => [FakeJobQueueClient::makeJob('job-2', 'success', 20)]]
        );
        $factory = new FakeProjectClientsFactory(
            ['100' => self::enabledProject('100'), '200' => self::enabledProject('200')],
            ['100' => self::clientsWith($queueClient1), '200' => self::clientsWith($queueClient2)]
        );
        $runner = new BatchRunner($factory, 10, 5, $this->sleepRecorder());

        $summary = $runner->run(['100', '200'], true, new BufferedOutput(), $this->collector());

        $this->assertCount(2, $this->results);
        $byProject = [];
        foreach ($this->results as $result) {
            $byProject[$result->projectId] = $result;
        }
        $this->assertSame('error', $byProject['100']->status);
        $this->assertSame('boom', $byProject['100']->error);
        $this->assertTrue($byProject['100']->isFailed());
        $this->assertSame('success', $byProject['200']->status);

        $this->assertSame(2, $summary['attempted']);
        $this->assertSame(1, $summary['migrated']);
        $this->assertSame(1, $summary['failed']);
    }

    public function testSkipsDisabledAndDeletedProjectsWithoutCreatingTokenOrJob(): void
    {
        $factory = new FakeProjectClientsFactory([
            '100' => ['id' => '100', 'name' => 'Off', 'isDisabled' => true],
            // A deleted project surfaces as a Manage API 404 and is reported the same way.
            '200' => new ManageClientException('Project not found', 404),
        ]);
        $runner = new BatchRunner($factory, 10, 5, $this->sleepRecorder());

        $summary = $runner->run(['100', '200'], true, new BufferedOutput(), $this->collector());

        // No ephemeral token may be created for a project that will not be migrated.
        $this->assertSame([], $factory->createClientsCalls);
        $this->assertSame(ProjectResult::STATUS_SKIPPED_DISABLED, $this->results[0]->status);
        $this->assertSame(ProjectResult::STATUS_SKIPPED_DISABLED, $this->results[1]->status);
        $this->assertNull($this->results[0]->jobId);
        $this->assertSame(2, $summary['skippedDisabled']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame([], $this->sleeps);
    }

    public function testManageErrorOtherThan404MarksProjectFailed(): void
    {
        $factory = new FakeProjectClientsFactory(
            ['100' => new ManageClientException('Internal error', 500)]
        );
        $runner = new BatchRunner($factory, 10, 5, $this->sleepRecorder());

        $summary = $runner->run(['100'], true, new BufferedOutput(), $this->collector());

        $this->assertSame(ProjectResult::STATUS_ERROR, $this->results[0]->status);
        $this->assertSame('Internal error', $this->results[0]->error);
        $this->assertSame(1, $summary['failed']);
    }

    public function testTransportFailureOnProjectLookupDoesNotAbortTheBatch(): void
    {
        // A DNS/connect failure surfaces as a Guzzle ConnectException, not a Manage API
        // ClientException - it must still resolve as a per-project error.
        $queueClient = new FakeJobQueueClient(
            [FakeJobQueueClient::makeJob('job-2', 'created')],
            ['job-2' => [FakeJobQueueClient::makeJob('job-2', 'success', 2)]]
        );
        $factory = new FakeProjectClientsFactory(
            [
                '100' => new ConnectException('cURL error 6: Could not resolve host', new Request('GET', '/')),
                '200' => self::enabledProject('200'),
            ],
            ['200' => self::clientsWith($queueClient)]
        );
        $runner = new BatchRunner($factory, 10, 5, $this->sleepRecorder());

        $summary = $runner->run(['100', '200'], true, new BufferedOutput(), $this->collector());

        $byProject = [];
        foreach ($this->results as $result) {
            $byProject[$result->projectId] = $result;
        }
        $this->assertSame(ProjectResult::STATUS_ERROR, $byProject['100']->status);
        $this->assertSame('success', $byProject['200']->status);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(1, $summary['migrated']);
    }

    public function testSkipsProjectWithoutOrchestratorConfigurations(): void
    {
        $queueClient = new FakeJobQueueClient();
        $factory = new FakeProjectClientsFactory(
            ['100' => self::enabledProject('100')],
            ['100' => self::clientsWith($queueClient, false)]
        );
        $runner = new BatchRunner($factory, 10, 5, $this->sleepRecorder());

        $summary = $runner->run(['100'], true, new BufferedOutput(), $this->collector());

        // No queue API call and no job for a project with nothing to migrate.
        $this->assertSame(0, $queueClient->listJobsCalls);
        $this->assertSame([], $queueClient->createdJobs);
        $this->assertSame(ProjectResult::STATUS_SKIPPED_NO_ORCHESTRATIONS, $this->results[0]->status);
        $this->assertSame(1, $summary['skippedNoOrchestrations']);
    }

    public function testSkipsProjectWithLiveMigrationJob(): void
    {
        $queueClient = new FakeJobQueueClient(
            [],
            [],
            [FakeJobQueueClient::makeJob('existing-job', 'processing')]
        );
        $factory = new FakeProjectClientsFactory(
            ['100' => self::enabledProject('100')],
            ['100' => self::clientsWith($queueClient)]
        );
        $runner = new BatchRunner($factory, 10, 5, $this->sleepRecorder());

        $summary = $runner->run(['100'], true, new BufferedOutput(), $this->collector());

        $this->assertSame([], $queueClient->createdJobs);
        $this->assertSame(ProjectResult::STATUS_SKIPPED_JOB_RUNNING, $this->results[0]->status);
        $this->assertSame(1, $summary['skippedJobRunning']);
        // The scripted return does not depend on the query, so assert the query itself: a guard
        // asking for another component or for terminal statuses would skip or submit wrongly.
        $this->assertSame(
            [
                'component' => ['keboola.flow-migration-tool'],
                'limit' => 1,
                'status' => ['created', 'waiting', 'processing', 'terminating'],
            ],
            $queueClient->listJobsQueries[0]
        );
    }

    public function testDeduplicatesInputProjectIds(): void
    {
        $queueClient = new FakeJobQueueClient(
            [FakeJobQueueClient::makeJob('job-1', 'created')],
            ['job-1' => [FakeJobQueueClient::makeJob('job-1', 'success', 1)]]
        );
        $factory = new FakeProjectClientsFactory(
            ['100' => self::enabledProject('100')],
            ['100' => self::clientsWith($queueClient)]
        );
        $runner = new BatchRunner($factory, 10, 5, $this->sleepRecorder());

        $summary = $runner->run(['100', '100', '100'], true, new BufferedOutput(), $this->collector());

        $this->assertSame(1, $summary['attempted']);
        $this->assertCount(1, $queueClient->createdJobs);
        $this->assertCount(1, $this->results);
    }

    public function testTwoConsecutivePollFailuresAreToleratedAndJobFinishes(): void
    {
        $queueClient = new FakeJobQueueClient(
            [FakeJobQueueClient::makeJob('job-1', 'created')],
            ['job-1' => [
                new RuntimeException('blip 1'),
                new RuntimeException('blip 2'),
                FakeJobQueueClient::makeJob('job-1', 'success', 7),
            ]]
        );
        $factory = new FakeProjectClientsFactory(
            ['100' => self::enabledProject('100')],
            ['100' => self::clientsWith($queueClient)]
        );
        $runner = new BatchRunner($factory, 10, 5, $this->sleepRecorder());

        $summary = $runner->run(['100'], true, new BufferedOutput(), $this->collector());

        $this->assertSame('success', $this->results[0]->status);
        $this->assertSame(1, $summary['migrated']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame([5, 5, 5], $this->sleeps);
    }

    public function testThreeConsecutivePollFailuresMarkProjectFailedWithJobIdKept(): void
    {
        $queueClient = new FakeJobQueueClient(
            [FakeJobQueueClient::makeJob('job-1', 'created')],
            ['job-1' => [
                new RuntimeException('down 1'),
                new RuntimeException('down 2'),
                new RuntimeException('down 3'),
            ]]
        );
        $factory = new FakeProjectClientsFactory(
            ['100' => self::enabledProject('100')],
            ['100' => self::clientsWith($queueClient)]
        );
        $runner = new BatchRunner($factory, 10, 5, $this->sleepRecorder());

        $summary = $runner->run(['100'], true, new BufferedOutput(), $this->collector());

        $this->assertSame(ProjectResult::STATUS_ERROR, $this->results[0]->status);
        // The job id must survive into the report - the job may still be running server-side.
        $this->assertSame('job-1', $this->results[0]->jobId);
        $this->assertIsString($this->results[0]->error);
        $this->assertStringContainsString('polling gave up', $this->results[0]->error);
        $this->assertSame(1, $summary['failed']);
    }

    public function testConcurrencyWindowCapsInFlightJobsAndRefills(): void
    {
        // One shared fake for both projects makes the cross-project call order observable.
        $queueClient = new FakeJobQueueClient(
            [
                FakeJobQueueClient::makeJob('job-1', 'created'),
                FakeJobQueueClient::makeJob('job-2', 'created'),
            ],
            [
                'job-1' => [
                    FakeJobQueueClient::makeJob('job-1', 'processing'),
                    FakeJobQueueClient::makeJob('job-1', 'success', 1),
                ],
                'job-2' => [FakeJobQueueClient::makeJob('job-2', 'success', 1)],
            ]
        );
        $clients = self::clientsWith($queueClient);
        $factory = new FakeProjectClientsFactory(
            ['100' => self::enabledProject('100'), '200' => self::enabledProject('200')],
            ['100' => $clients, '200' => $clients]
        );
        $runner = new BatchRunner($factory, 1, 5, $this->sleepRecorder());

        $summary = $runner->run(['100', '200'], true, new BufferedOutput(), $this->collector());

        // With concurrency=1, job-2 must not be created until job-1 has finished.
        $this->assertSame(
            [
                ['listJobs'],
                ['createJob', 'job-1'],
                ['getJob', 'job-1'],
                ['getJob', 'job-1'],
                ['listJobs'],
                ['createJob', 'job-2'],
                ['getJob', 'job-2'],
            ],
            $queueClient->calls
        );
        $this->assertSame(2, $summary['migrated']);
    }
}
