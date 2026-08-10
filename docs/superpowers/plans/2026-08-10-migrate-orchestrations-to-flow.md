# manage:migrate-orchestrations-to-flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `cli-utils` command that drives the automated `keboola.orchestrator` → `keboola.flow` migration across a batch of projects on one stack by creating and supervising `keboola.flow-migration-tool` jobs (AJDA-3117).

**Architecture:** A thin Symfony Command (`MigrateOrchestrationsToFlow`) wires input parsing, CSV reporting, and summary output around a fully unit-tested plain class (`FlowMigrationBatchRunner`) that owns all batch logic: per-project skip rules, job submission, a bounded concurrency window, and polling. The only network seam is `FlowMigrationProjectClientsFactory` (Manage API + per-project ephemeral-token clients), which tests replace with fakes that skip the parent constructor — the same trick `tests/FakeComponents.php` already uses.

**Tech Stack:** PHP 8.3, Symfony Console 7.4, `keboola/kbc-manage-api-php-client` v7.1.1, `keboola/job-queue-api-php-client` 5.2.0, `keboola/service-client` 1.5.1, `keboola/storage-api-client` v18.7.0, PHPUnit 11. **No composer changes needed — everything is already installed.**

**Spec:** `docs/superpowers/specs/2026-08-10-migrate-orchestrations-to-flow-design.md`

## Global Constraints

- All commands run via `docker compose run --rm dev ...` (dev image has a bind mount; run `docker compose run --rm dev composer install` once first).
- PSR-0 autoloading: class `Keboola\Console\Command\X` **must** live at `src/Keboola/Console/Command/X.php`. Tests are PSR-4: `Keboola\Console\Tests\` → `tests/`.
- Commands are registered manually in `cli.php` — no auto-discovery.
- phpstan level 9 must stay clean (`composer phpstan`, covers `src/` only).
- PSR-2 must stay clean repo-wide: `./vendor/bin/phpcs --standard=psr2 --ignore=vendor -n .` (CI checks `tests/` too). Keep lines under 120 chars.
- Argument order `<token> <url> ...` is mandatory (compatibility with `manage:call-on-stacks`).
- Dry-run by default behind `-f`/`--force` — but note the twist: here "dry-run" still creates real jobs (with `parameters.dryRun: true`) and real ephemeral tokens.
- Do **not** use constructor property promotion or `readonly` — `src/` uses classic properties exclusively and phpcs PSR-2 is configured for that style.
- Git commits: conventional format, English, **no AI attribution of any kind**.
- Exact strings that must be used verbatim:
  - command name: `manage:migrate-orchestrations-to-flow`
  - token description: `AJDA-3117 keboola.orchestrator to keboola.flow migration (batch driver)`
  - token: `expiresIn` 43200, `canManageBuckets` true, `canReadAllFileUploads` true, `componentAccess` = `keboola.orchestrator`, `keboola.flow`, `keboola.scheduler`, `keboola.flow-migration-tool`
  - job payload parameters: `{"mode": "project", "orchestrationIds": [], "skipBroken": true, "dryRun": <!force>}`
  - CSV header: `projectId;jobId;status;durationSeconds;error` (`;` delimiter)

**Verified SDK facts (do not re-derive, they were read from `vendor/`):**
- `Keboola\JobQueueClient\Client::__construct(string $publicApiUrl, string $storageToken, array $options = [])`; `createJob(JobData): DTO\Job`; `getJob(string): DTO\Job`; `listJobs(ListJobsOptions): array` (native `array` return type — safe to compare `!== []`).
- `Keboola\JobQueueClient\JobData::__construct(string $componentId, ?string $configId = null, array $configData = [], string $mode = 'run', ...)`; `getArray()` keys: `component`, `config`, `mode`, `configRowIds`, `tag`, `branchId`, `orchestrationJobId`, `parentRunId`, `configData`.
- `Keboola\JobQueueClient\DTO\Job` is `readonly` with private constructor; build instances via `Job::fromApiResponse(array)` — it reads **all** of these keys without `??`: `id, runId, parentRunId, project, token, status, desiredStatus, mode, component, config, configData, configRowIds, tag, createdTime, startTime, endTime, durationSeconds, result, usageData, isFinished, url, branchId, variableValuesId, variableValuesData, backend, executor, metrics, behavior, parallelism, type, orchestrationJobId, orchestrationTaskId, onlyOrchestrationTaskIds, previousJobId`. `project` needs `['id' => string]`, `token` needs `['id' => string, 'description' => ?string]`; `variableValuesData`, `backend`, `behavior` accept `[]`.
- `Keboola\JobQueueClient\JobStatuses` is a string-backed enum: `CREATED, PROCESSING, TERMINATING, TERMINATED, WAITING, SUCCESS, ERROR, WARNING, CANCELLED`.
- `Keboola\ServiceClient\ServiceClient::__construct(string $hostnameSuffix)`; `getQueueUrl(): string` returns `https://queue.<suffix>`.
- `Keboola\ManageApi\Client::getProject($id)` and `createProjectStorageToken($projectId, array $params)` have **no declared return types** (implicit mixed) — direct offset access like `$tokenInfo['token']` passes phpstan level 9 (proven by the identical pattern in `MigrateDataAppsOrchestratorTasks.php:199`). Do not add `is_array()` guards on their results — phpstan would not flag either way, and the existing code style omits them.
- `Keboola\ManageApi\ClientException` extends `\Exception`; HTTP status is available via `getCode()`.

---

### Task 1: Result and clients DTOs

**Files:**
- Create: `src/Keboola/Console/Command/FlowMigrationProjectResult.php`
- Create: `src/Keboola/Console/Command/FlowMigrationProjectClients.php`
- Test: `tests/FlowMigrationProjectResultTest.php`

**Interfaces:**
- Consumes: `Keboola\JobQueueClient\JobStatuses` (vendor enum), `Keboola\StorageApi\Components`, `Keboola\JobQueueClient\Client` (vendor classes).
- Produces:
  - `FlowMigrationProjectResult::__construct(string $projectId, ?string $jobId, string $status, ?int $durationSeconds, ?string $error)` with public typed properties `$projectId`, `$jobId`, `$status`, `$durationSeconds`, `$error`; methods `isSkipped(): bool`, `isFailed(): bool`; constants `STATUS_SKIPPED_DISABLED = 'skipped-disabled'`, `STATUS_SKIPPED_NO_ORCHESTRATIONS = 'skipped-no-orchestrations'`, `STATUS_SKIPPED_JOB_RUNNING = 'skipped-job-running'`, `STATUS_ERROR = 'error'`.
  - `FlowMigrationProjectClients::__construct(Components $components, Client $queueClient)` with public typed properties `$components`, `$queueClient`.

- [ ] **Step 1: Install dependencies (once)**

Run: `docker compose run --rm dev composer install`
Expected: exits 0, `vendor/` present.

- [ ] **Step 2: Write the failing test**

Create `tests/FlowMigrationProjectResultTest.php`:

```php
<?php

declare(strict_types=1);

namespace Keboola\Console\Tests;

use Keboola\Console\Command\FlowMigrationProjectResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FlowMigrationProjectResultTest extends TestCase
{
    #[DataProvider('provideStatuses')]
    public function testClassification(string $status, bool $expectedSkipped, bool $expectedFailed): void
    {
        $result = new FlowMigrationProjectResult('123', null, $status, null, null);

        $this->assertSame($expectedSkipped, $result->isSkipped());
        $this->assertSame($expectedFailed, $result->isFailed());
    }

    /**
     * @return iterable<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function provideStatuses(): iterable
    {
        yield 'job success is neither skipped nor failed' => ['success', false, false];
        yield 'job warning counts as migrated, not failed' => ['warning', false, false];
        yield 'job error is failed' => ['error', false, true];
        yield 'job terminated is failed' => ['terminated', false, true];
        yield 'job cancelled is failed' => ['cancelled', false, true];
        yield 'skipped disabled' => [FlowMigrationProjectResult::STATUS_SKIPPED_DISABLED, true, false];
        yield 'skipped no orchestrations' => [FlowMigrationProjectResult::STATUS_SKIPPED_NO_ORCHESTRATIONS, true, false];
        yield 'skipped job running' => [FlowMigrationProjectResult::STATUS_SKIPPED_JOB_RUNNING, true, false];
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `docker compose run --rm dev ./vendor/bin/phpunit tests/FlowMigrationProjectResultTest.php`
Expected: FAIL — `Class "Keboola\Console\Command\FlowMigrationProjectResult" not found`

- [ ] **Step 4: Write the implementation**

Create `src/Keboola/Console/Command/FlowMigrationProjectResult.php`:

```php
<?php

declare(strict_types=1);

namespace Keboola\Console\Command;

use Keboola\JobQueueClient\JobStatuses;

/**
 * Terminal outcome of one project in the flow-migration batch: either a finished
 * keboola.flow-migration-tool job (status = job terminal status), a skip, or a driver-side error.
 */
class FlowMigrationProjectResult
{
    public const STATUS_SKIPPED_DISABLED = 'skipped-disabled';
    public const STATUS_SKIPPED_NO_ORCHESTRATIONS = 'skipped-no-orchestrations';
    public const STATUS_SKIPPED_JOB_RUNNING = 'skipped-job-running';
    public const STATUS_ERROR = 'error';

    public string $projectId;
    public ?string $jobId;
    public string $status;
    public ?int $durationSeconds;
    public ?string $error;

    public function __construct(string $projectId, ?string $jobId, string $status, ?int $durationSeconds, ?string $error)
    {
        $this->projectId = $projectId;
        $this->jobId = $jobId;
        $this->status = $status;
        $this->durationSeconds = $durationSeconds;
        $this->error = $error;
    }

    public function isSkipped(): bool
    {
        return in_array($this->status, [
            self::STATUS_SKIPPED_DISABLED,
            self::STATUS_SKIPPED_NO_ORCHESTRATIONS,
            self::STATUS_SKIPPED_JOB_RUNNING,
        ], true);
    }

    public function isFailed(): bool
    {
        // Anything that is not a skip and not a successful terminal job status is a failure —
        // unexpected statuses fail loud rather than passing silently.
        return !$this->isSkipped()
            && !in_array($this->status, [JobStatuses::SUCCESS->value, JobStatuses::WARNING->value], true);
    }
}
```

Create `src/Keboola/Console/Command/FlowMigrationProjectClients.php`:

```php
<?php

declare(strict_types=1);

namespace Keboola\Console\Command;

use Keboola\JobQueueClient\Client as JobQueueClient;
use Keboola\StorageApi\Components;

/**
 * Per-project API clients bound to one ephemeral project storage token.
 */
class FlowMigrationProjectClients
{
    public Components $components;
    public JobQueueClient $queueClient;

    public function __construct(Components $components, JobQueueClient $queueClient)
    {
        $this->components = $components;
        $this->queueClient = $queueClient;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose run --rm dev ./vendor/bin/phpunit tests/FlowMigrationProjectResultTest.php`
Expected: PASS (8 tests)

- [ ] **Step 6: Static analysis and code style**

Run: `docker compose run --rm dev composer phpstan && docker compose run --rm dev ./vendor/bin/phpcs --standard=psr2 --ignore=vendor -n .`
Expected: both exit 0.

- [ ] **Step 7: Commit**

```bash
git add src/Keboola/Console/Command/FlowMigrationProjectResult.php \
    src/Keboola/Console/Command/FlowMigrationProjectClients.php \
    tests/FlowMigrationProjectResultTest.php
git commit -m "feat: add flow migration result and per-project clients DTOs"
```

---

### Task 2: FlowMigrationProjectClientsFactory (network seam)

**Files:**
- Create: `src/Keboola/Console/Command/FlowMigrationProjectClientsFactory.php`

**Interfaces:**
- Consumes: `FlowMigrationProjectClients` (Task 1), `Keboola\ManageApi\Client`, `Keboola\StorageApi\Client`, `Keboola\StorageApi\Components`, `Keboola\JobQueueClient\Client`.
- Produces:
  - `FlowMigrationProjectClientsFactory::__construct(Keboola\ManageApi\Client $manageClient, string $connectionUrl, string $queueApiUrl)`
  - `getProject(string $projectId): array` — Manage API project detail (throws `Keboola\ManageApi\ClientException`, 404 = deleted project)
  - `createProjectClients(string $projectId): FlowMigrationProjectClients` — creates the ephemeral token and both clients

This class is pure network wiring with no branching logic — it is **not** unit-tested (would only test the mock). It is replaced by a fake in Task 3 and its constants are asserted indirectly through the command smoke test in Task 7. Both public methods must stay non-final and overridable.

- [ ] **Step 1: Write the implementation**

Create `src/Keboola/Console/Command/FlowMigrationProjectClientsFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Keboola\Console\Command;

use Keboola\JobQueueClient\Client as JobQueueClient;
use Keboola\ManageApi\Client as ManageClient;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\Components;

/**
 * Creates per-project API clients for the flow-migration batch. Every project gets its own
 * ephemeral storage token; the token expires on its own, so there is no cleanup step.
 */
class FlowMigrationProjectClientsFactory
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

    public function createProjectClients(string $projectId): FlowMigrationProjectClients
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

        return new FlowMigrationProjectClients(
            new Components($storageClient),
            new JobQueueClient($this->queueApiUrl, $tokenInfo['token'])
        );
    }
}
```

- [ ] **Step 2: Static analysis and code style**

Run: `docker compose run --rm dev composer phpstan && docker compose run --rm dev ./vendor/bin/phpcs --standard=psr2 --ignore=vendor -n .`
Expected: both exit 0. (If phpstan complains about `$tokenInfo['token']`, something changed in the vendor package — compare with the working pattern in `src/Keboola/Console/Command/MigrateDataAppsOrchestratorTasks.php:199-213` and match it.)

- [ ] **Step 3: Commit**

```bash
git add src/Keboola/Console/Command/FlowMigrationProjectClientsFactory.php
git commit -m "feat: add per-project clients factory with ephemeral token creation"
```

---

### Task 3: Test fakes for the queue client and the clients factory

**Files:**
- Create: `tests/FakeJobQueueClient.php`
- Create: `tests/FakeFlowMigrationProjectClientsFactory.php`
- Test: `tests/FakeJobQueueClientTest.php`

**Interfaces:**
- Consumes: `FlowMigrationProjectClients`, `FlowMigrationProjectClientsFactory` (Tasks 1-2), vendor `Client`, `DTO\Job`, `JobData`, `ListJobsOptions`.
- Produces (used by Tasks 4-6):
  - `FakeJobQueueClient::__construct(array $createJobReturns = [], array $getJobSequences = [], array $listJobsReturn = [])` — scripted returns; `$getJobSequences` maps jobId → list of `Job|Throwable` consumed one per poll.
  - public inspection fields: `array $createdJobs` (list of `JobData->getArray()` payloads), `int $listJobsCalls`, `array $calls` (ordered log of `['createJob', <component>]` / `['getJob', <jobId>]` / `['listJobs']` entries).
  - `FakeJobQueueClient::makeJob(string $id, string $status, ?int $durationSeconds = null, ?array $result = null): Job` — builds a real `DTO\Job` fixture; `isFinished` is true for terminal statuses.
  - `FakeFlowMigrationProjectClientsFactory::__construct(array $projects, array $projectClients = [])` — `$projects`: projectId → project detail array or `Throwable` to throw; `$projectClients`: projectId → `FlowMigrationProjectClients` or `Throwable`; public field `array $createClientsCalls` (list of projectIds).

- [ ] **Step 1: Write the failing sanity test**

Create `tests/FakeJobQueueClientTest.php`:

```php
<?php

declare(strict_types=1);

namespace Keboola\Console\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

class FakeJobQueueClientTest extends TestCase
{
    public function testMakeJobBuildsRealDtoWithTerminalFlag(): void
    {
        $running = FakeJobQueueClient::makeJob('1', 'processing');
        $finished = FakeJobQueueClient::makeJob('1', 'success', 42, ['message' => 'ok']);

        $this->assertFalse($running->isFinished);
        $this->assertTrue($finished->isFinished);
        $this->assertSame('success', $finished->status);
        $this->assertSame(42, $finished->durationSeconds);
        $this->assertSame(['message' => 'ok'], $finished->result);
    }

    public function testGetJobConsumesScriptedSequenceAndThrowsThrowables(): void
    {
        $fake = new FakeJobQueueClient([], ['job-1' => [
            new RuntimeException('network blip'),
            FakeJobQueueClient::makeJob('job-1', 'success'),
        ]]);

        try {
            $fake->getJob('job-1');
            $this->fail('First scripted outcome should throw');
        } catch (RuntimeException $e) {
            $this->assertSame('network blip', $e->getMessage());
        }

        $this->assertSame('success', $fake->getJob('job-1')->status);
        $this->assertSame([['getJob', 'job-1'], ['getJob', 'job-1']], $fake->calls);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm dev ./vendor/bin/phpunit tests/FakeJobQueueClientTest.php`
Expected: FAIL — `Class "Keboola\Console\Tests\FakeJobQueueClient" not found`

- [ ] **Step 3: Write the fakes**

Create `tests/FakeJobQueueClient.php`:

```php
<?php

declare(strict_types=1);

namespace Keboola\Console\Tests;

use Keboola\JobQueueClient\Client as JobQueueClient;
use Keboola\JobQueueClient\DTO\Job;
use Keboola\JobQueueClient\JobData;
use Keboola\JobQueueClient\ListJobsOptions;
use RuntimeException;
use Throwable;

/**
 * In-memory double for Keboola\JobQueueClient\Client with scripted responses.
 * Deliberately does not call parent::__construct() so no HTTP client is ever
 * created - same trick as FakeComponents.
 */
class FakeJobQueueClient extends JobQueueClient
{
    /** @var array<int, array<string, mixed>> recorded JobData->getArray() payloads */
    public array $createdJobs = [];

    /** @var array<int, array<int, string>> ordered call log: [method, id] */
    public array $calls = [];

    public int $listJobsCalls = 0;

    /** @var array<int, Job> */
    private array $createJobReturns;

    /** @var array<string, array<int, Job|Throwable>> */
    private array $getJobSequences;

    /** @var array<int, Job> */
    private array $listJobsReturn;

    /**
     * @param array<int, Job> $createJobReturns successive createJob() returns
     * @param array<string, array<int, Job|Throwable>> $getJobSequences jobId => successive getJob() outcomes
     * @param array<int, Job> $listJobsReturn returned by every listJobs() call
     */
    public function __construct(array $createJobReturns = [], array $getJobSequences = [], array $listJobsReturn = [])
    {
        $this->createJobReturns = $createJobReturns;
        $this->getJobSequences = $getJobSequences;
        $this->listJobsReturn = $listJobsReturn;
    }

    public function createJob(JobData $jobData): Job
    {
        $this->createdJobs[] = $jobData->getArray();
        $job = array_shift($this->createJobReturns);
        if ($job === null) {
            throw new RuntimeException('FakeJobQueueClient: no scripted createJob return left');
        }
        $this->calls[] = ['createJob', $job->id];

        return $job;
    }

    public function getJob(string $jobId): Job
    {
        $this->calls[] = ['getJob', $jobId];
        $sequence = $this->getJobSequences[$jobId] ?? [];
        if ($sequence === []) {
            throw new RuntimeException(sprintf('FakeJobQueueClient: no scripted getJob outcome left for "%s"', $jobId));
        }
        $outcome = array_shift($sequence);
        $this->getJobSequences[$jobId] = $sequence;
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return $outcome;
    }

    public function listJobs(ListJobsOptions $listOptions): array
    {
        $this->calls[] = ['listJobs'];
        $this->listJobsCalls++;

        return $this->listJobsReturn;
    }

    /**
     * Builds a real DTO\Job through its public factory so the fixture stays in sync with the SDK.
     *
     * @param array<mixed>|null $result
     */
    public static function makeJob(string $id, string $status, ?int $durationSeconds = null, ?array $result = null): Job
    {
        $terminalStatuses = ['success', 'error', 'warning', 'terminated', 'cancelled'];

        return Job::fromApiResponse([
            'id' => $id,
            'runId' => $id,
            'parentRunId' => '',
            'project' => ['id' => '123'],
            'token' => ['id' => '456', 'description' => 'test token'],
            'status' => $status,
            'desiredStatus' => 'processing',
            'mode' => 'run',
            'component' => 'keboola.flow-migration-tool',
            'config' => null,
            'configData' => null,
            'configRowIds' => null,
            'tag' => null,
            'createdTime' => '2026-08-10T10:00:00+00:00',
            'startTime' => null,
            'endTime' => null,
            'durationSeconds' => $durationSeconds,
            'result' => $result,
            'usageData' => null,
            'isFinished' => in_array($status, $terminalStatuses, true),
            'url' => sprintf('https://queue.example.com/jobs/%s', $id),
            'branchId' => null,
            'variableValuesId' => null,
            'variableValuesData' => [],
            'backend' => [],
            'behavior' => [],
            'executor' => null,
            'metrics' => null,
            'parallelism' => null,
            'type' => 'standard',
            'orchestrationJobId' => null,
            'orchestrationTaskId' => null,
            'onlyOrchestrationTaskIds' => null,
            'previousJobId' => null,
        ]);
    }
}
```

Create `tests/FakeFlowMigrationProjectClientsFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Keboola\Console\Tests;

use Keboola\Console\Command\FlowMigrationProjectClients;
use Keboola\Console\Command\FlowMigrationProjectClientsFactory;
use RuntimeException;
use Throwable;

/**
 * In-memory double for FlowMigrationProjectClientsFactory. Deliberately does not call
 * parent::__construct() so no Manage API client is needed - same trick as FakeComponents.
 */
class FakeFlowMigrationProjectClientsFactory extends FlowMigrationProjectClientsFactory
{
    /** @var array<int, string> projectIds passed to createProjectClients() */
    public array $createClientsCalls = [];

    /** @var array<string, array<mixed>|Throwable> */
    private array $projects;

    /** @var array<string, FlowMigrationProjectClients|Throwable> */
    private array $projectClients;

    /**
     * @param array<string, array<mixed>|Throwable> $projects projectId => Manage project detail, or Throwable to throw
     * @param array<string, FlowMigrationProjectClients|Throwable> $projectClients projectId => clients, or Throwable
     */
    public function __construct(array $projects, array $projectClients = [])
    {
        $this->projects = $projects;
        $this->projectClients = $projectClients;
    }

    public function getProject(string $projectId): array
    {
        if (!array_key_exists($projectId, $this->projects)) {
            throw new RuntimeException(sprintf('FakeFlowMigrationProjectClientsFactory: unknown project "%s"', $projectId));
        }
        $project = $this->projects[$projectId];
        if ($project instanceof Throwable) {
            throw $project;
        }

        return $project;
    }

    public function createProjectClients(string $projectId): FlowMigrationProjectClients
    {
        $this->createClientsCalls[] = $projectId;
        if (!array_key_exists($projectId, $this->projectClients)) {
            throw new RuntimeException(sprintf('FakeFlowMigrationProjectClientsFactory: no clients for project "%s"', $projectId));
        }
        $clients = $this->projectClients[$projectId];
        if ($clients instanceof Throwable) {
            throw $clients;
        }

        return $clients;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose run --rm dev ./vendor/bin/phpunit tests/FakeJobQueueClientTest.php`
Expected: PASS (2 tests). If `Job::fromApiResponse` throws about a missing key, the SDK changed — add the missing key with a null/empty value to `makeJob()`.

- [ ] **Step 5: Code style**

Run: `docker compose run --rm dev ./vendor/bin/phpcs --standard=psr2 --ignore=vendor -n .`
Expected: exit 0.

- [ ] **Step 6: Commit**

```bash
git add tests/FakeJobQueueClient.php tests/FakeFlowMigrationProjectClientsFactory.php tests/FakeJobQueueClientTest.php
git commit -m "test: add fakes for job queue client and flow migration clients factory"
```

---

### Task 4: FlowMigrationBatchRunner — core loop, submission, polling

**Files:**
- Create: `src/Keboola/Console/Command/FlowMigrationBatchRunner.php`
- Test: `tests/FlowMigrationBatchRunnerTest.php`

**Interfaces:**
- Consumes: Tasks 1-3 classes; vendor `JobData`, `JobStatuses`, `ListJobsOptions`, `ListComponentConfigurationsOptions`, `DTO\Job`; `tests/FakeComponents.php` (existing, constructor `new FakeComponents(array $configsByComponent)`).
- Produces:
  - `FlowMigrationBatchRunner::__construct(FlowMigrationProjectClientsFactory $clientsFactory, int $concurrency, int $pollIntervalSeconds, ?callable $sleep = null)` — `$sleep` signature `callable(int): void`, defaults to PHP `sleep()`.
  - `run(array $projectIds, bool $force, OutputInterface $output, callable $onProjectFinished): array` — `$onProjectFinished` receives one `FlowMigrationProjectResult` per input project; returns summary shape `array{attempted: int, migrated: int, migratedWithWarning: int, skippedNoOrchestrations: int, skippedDisabled: int, skippedJobRunning: int, failed: int}`.
  - public constants `ORCHESTRATOR_COMPONENT_ID = 'keboola.orchestrator'`, `MIGRATION_COMPONENT_ID = 'keboola.flow-migration-tool'`.

In this task the runner handles enabled projects with orchestrations and no live migration job (the happy pipeline: guard query → createJob → poll → terminal result). Skip rules come in Task 5, poll-failure tolerance in Task 6.

- [ ] **Step 1: Write the failing tests**

Create `tests/FlowMigrationBatchRunnerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Keboola\Console\Tests;

use Closure;
use Keboola\Console\Command\FlowMigrationBatchRunner;
use Keboola\Console\Command\FlowMigrationProjectClients;
use Keboola\Console\Command\FlowMigrationProjectResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class FlowMigrationBatchRunnerTest extends TestCase
{
    /** @var array<int, FlowMigrationProjectResult> */
    private array $results = [];

    /** @var array<int, int> */
    private array $sleeps = [];

    private function collector(): Closure
    {
        return function (FlowMigrationProjectResult $result): void {
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

    private static function clientsWith(FakeJobQueueClient $queueClient, bool $hasOrchestrations = true): FlowMigrationProjectClients
    {
        $configs = $hasOrchestrations
            ? ['keboola.orchestrator' => [['id' => 'orch-1', 'name' => 'Daily load', 'configuration' => []]]]
            : [];

        return new FlowMigrationProjectClients(new FakeComponents($configs), $queueClient);
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
        $factory = new FakeFlowMigrationProjectClientsFactory(
            ['100' => self::enabledProject('100')],
            ['100' => self::clientsWith($queueClient)]
        );
        $runner = new FlowMigrationBatchRunner($factory, 10, 5, $this->sleepRecorder());

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
        $factory = new FakeFlowMigrationProjectClientsFactory(
            ['100' => self::enabledProject('100')],
            ['100' => self::clientsWith($queueClient)]
        );
        $runner = new FlowMigrationBatchRunner($factory, 10, 5, $this->sleepRecorder());

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
        $factory = new FakeFlowMigrationProjectClientsFactory(
            ['100' => self::enabledProject('100'), '200' => self::enabledProject('200')],
            ['100' => self::clientsWith($queueClient1), '200' => self::clientsWith($queueClient2)]
        );
        $runner = new FlowMigrationBatchRunner($factory, 10, 5, $this->sleepRecorder());

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

    public function testWarningJobCountsAsMigratedWithWarning(): void
    {
        $queueClient = new FakeJobQueueClient(
            [FakeJobQueueClient::makeJob('job-1', 'created')],
            ['job-1' => [FakeJobQueueClient::makeJob('job-1', 'warning', 5, ['message' => 'partial'])]]
        );
        $factory = new FakeFlowMigrationProjectClientsFactory(
            ['100' => self::enabledProject('100')],
            ['100' => self::clientsWith($queueClient)]
        );
        $runner = new FlowMigrationBatchRunner($factory, 10, 5, $this->sleepRecorder());

        $summary = $runner->run(['100'], true, new BufferedOutput(), $this->collector());

        $this->assertSame('warning', $this->results[0]->status);
        $this->assertFalse($this->results[0]->isFailed());
        $this->assertSame(1, $summary['migratedWithWarning']);
        $this->assertSame(0, $summary['migrated']);
        $this->assertSame(0, $summary['failed']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose run --rm dev ./vendor/bin/phpunit tests/FlowMigrationBatchRunnerTest.php`
Expected: FAIL — `Class "Keboola\Console\Command\FlowMigrationBatchRunner" not found`

- [ ] **Step 3: Write the implementation**

Create `src/Keboola/Console/Command/FlowMigrationBatchRunner.php`:

```php
<?php

declare(strict_types=1);

namespace Keboola\Console\Command;

use Keboola\JobQueueClient\Client as JobQueueClient;
use Keboola\JobQueueClient\DTO\Job;
use Keboola\JobQueueClient\JobData;
use Keboola\JobQueueClient\JobStatuses;
use Keboola\JobQueueClient\ListJobsOptions;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Drives keboola.flow-migration-tool jobs across a batch of projects with a bounded
 * concurrency window. Pure orchestration - all migration logic lives in the component.
 */
class FlowMigrationBatchRunner
{
    public const ORCHESTRATOR_COMPONENT_ID = 'keboola.orchestrator';
    public const MIGRATION_COMPONENT_ID = 'keboola.flow-migration-tool';

    // TERMINATING is included on top of the issue's created/waiting/processing: a terminating
    // job may still be executing migration writes, and skipping it strictly reduces overlap risk.
    private const LIVE_JOB_STATUSES = [
        JobStatuses::CREATED,
        JobStatuses::WAITING,
        JobStatuses::PROCESSING,
        JobStatuses::TERMINATING,
    ];

    private FlowMigrationProjectClientsFactory $clientsFactory;
    private int $concurrency;
    private int $pollIntervalSeconds;

    /** @var callable(int): void */
    private $sleep;

    public function __construct(
        FlowMigrationProjectClientsFactory $clientsFactory,
        int $concurrency,
        int $pollIntervalSeconds,
        ?callable $sleep = null
    ) {
        $this->clientsFactory = $clientsFactory;
        $this->concurrency = $concurrency;
        $this->pollIntervalSeconds = $pollIntervalSeconds;
        $this->sleep = $sleep ?? function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @param array<int, string> $projectIds
     * @param callable(FlowMigrationProjectResult): void $onProjectFinished invoked once per input project
     * @return array{
     *     attempted: int,
     *     migrated: int,
     *     migratedWithWarning: int,
     *     skippedNoOrchestrations: int,
     *     skippedDisabled: int,
     *     skippedJobRunning: int,
     *     failed: int
     * }
     */
    public function run(array $projectIds, bool $force, OutputInterface $output, callable $onProjectFinished): array
    {
        $pending = array_values(array_unique($projectIds));
        $summary = [
            'attempted' => count($pending),
            'migrated' => 0,
            'migratedWithWarning' => 0,
            'skippedNoOrchestrations' => 0,
            'skippedDisabled' => 0,
            'skippedJobRunning' => 0,
            'failed' => 0,
        ];
        /** @var array<string, array{jobId: string, queueClient: JobQueueClient, startedAt: float, pollFailures: int}> $inFlight */
        $inFlight = [];

        while ($pending !== [] || $inFlight !== []) {
            while (count($inFlight) < $this->concurrency && $pending !== []) {
                $projectId = array_shift($pending);
                $submission = $this->submitProject($projectId, $force, $output);
                if ($submission instanceof FlowMigrationProjectResult) {
                    $this->recordResult($submission, $summary, $output, $onProjectFinished);
                    continue;
                }
                $inFlight[$projectId] = $submission;
            }

            if ($inFlight === []) {
                continue;
            }

            ($this->sleep)($this->pollIntervalSeconds);
            $this->pollInFlightJobs($inFlight, $summary, $output, $onProjectFinished);
        }

        return $summary;
    }

    /**
     * Runs the per-project pipeline up to job creation. Returns an in-flight slot on success,
     * or an immediately-final FlowMigrationProjectResult (skip or driver-side error).
     *
     * @return FlowMigrationProjectResult|array{jobId: string, queueClient: JobQueueClient, startedAt: float, pollFailures: int}
     */
    private function submitProject(string $projectId, bool $force, OutputInterface $output)
    {
        try {
            $clients = $this->clientsFactory->createProjectClients($projectId);

            $liveJobs = $clients->queueClient->listJobs(
                (new ListJobsOptions())
                    ->setComponents([self::MIGRATION_COMPONENT_ID])
                    ->setStatuses(self::LIVE_JOB_STATUSES)
                    ->setLimit(1)
            );
            if ($liveJobs !== []) {
                return new FlowMigrationProjectResult(
                    $projectId,
                    null,
                    FlowMigrationProjectResult::STATUS_SKIPPED_JOB_RUNNING,
                    null,
                    'a keboola.flow-migration-tool job is already running in this project'
                );
            }

            $job = $clients->queueClient->createJob(new JobData(
                self::MIGRATION_COMPONENT_ID,
                null,
                [
                    'parameters' => [
                        'mode' => 'project',
                        'orchestrationIds' => [],
                        'skipBroken' => true,
                        'dryRun' => !$force,
                    ],
                ]
            ));
        } catch (Throwable $e) {
            return new FlowMigrationProjectResult(
                $projectId,
                null,
                FlowMigrationProjectResult::STATUS_ERROR,
                null,
                $e->getMessage()
            );
        }

        $this->writeLine($output, sprintf('Project %s: created job %s (%s)', $projectId, $job->id, $job->url));

        return [
            'jobId' => $job->id,
            'queueClient' => $clients->queueClient,
            'startedAt' => microtime(true),
            'pollFailures' => 0,
        ];
    }

    /**
     * @param array<string, array{jobId: string, queueClient: JobQueueClient, startedAt: float, pollFailures: int}> $inFlight
     * @param array{
     *     attempted: int,
     *     migrated: int,
     *     migratedWithWarning: int,
     *     skippedNoOrchestrations: int,
     *     skippedDisabled: int,
     *     skippedJobRunning: int,
     *     failed: int
     * } $summary
     * @param callable(FlowMigrationProjectResult): void $onProjectFinished
     */
    private function pollInFlightJobs(
        array &$inFlight,
        array &$summary,
        OutputInterface $output,
        callable $onProjectFinished
    ): void {
        foreach (array_keys($inFlight) as $projectId) {
            $slot = $inFlight[$projectId];
            $job = $slot['queueClient']->getJob($slot['jobId']);

            if (!$job->isFinished) {
                continue;
            }

            unset($inFlight[$projectId]);
            $durationSeconds = $job->durationSeconds ?? (int) round(microtime(true) - $slot['startedAt']);
            $this->recordResult(
                new FlowMigrationProjectResult(
                    $projectId,
                    $slot['jobId'],
                    $job->status,
                    $durationSeconds,
                    $this->extractJobError($job)
                ),
                $summary,
                $output,
                $onProjectFinished
            );
        }
    }

    private function extractJobError(Job $job): ?string
    {
        if ($job->isSuccess()) {
            return null;
        }
        $result = $job->result;
        if (is_array($result) && isset($result['message']) && is_scalar($result['message'])) {
            return (string) $result['message'];
        }

        return null;
    }

    /**
     * @param array{
     *     attempted: int,
     *     migrated: int,
     *     migratedWithWarning: int,
     *     skippedNoOrchestrations: int,
     *     skippedDisabled: int,
     *     skippedJobRunning: int,
     *     failed: int
     * } $summary
     * @param callable(FlowMigrationProjectResult): void $onProjectFinished
     */
    private function recordResult(
        FlowMigrationProjectResult $result,
        array &$summary,
        OutputInterface $output,
        callable $onProjectFinished
    ): void {
        $summaryKey = match ($result->status) {
            JobStatuses::SUCCESS->value => 'migrated',
            JobStatuses::WARNING->value => 'migratedWithWarning',
            FlowMigrationProjectResult::STATUS_SKIPPED_NO_ORCHESTRATIONS => 'skippedNoOrchestrations',
            FlowMigrationProjectResult::STATUS_SKIPPED_DISABLED => 'skippedDisabled',
            FlowMigrationProjectResult::STATUS_SKIPPED_JOB_RUNNING => 'skippedJobRunning',
            default => 'failed',
        };
        $summary[$summaryKey]++;

        $this->writeLine($output, sprintf(
            'Project %s: %s%s%s',
            $result->projectId,
            $result->status,
            $result->durationSeconds !== null ? sprintf(' in %d s', $result->durationSeconds) : '',
            $result->error !== null ? sprintf(' (%s)', $result->error) : ''
        ));

        $onProjectFinished($result);
    }

    private function writeLine(OutputInterface $output, string $message): void
    {
        $output->writeln(sprintf('[%s] %s', date('H:i:s'), $message));
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose run --rm dev ./vendor/bin/phpunit tests/FlowMigrationBatchRunnerTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Static analysis and code style**

Run: `docker compose run --rm dev composer phpstan && docker compose run --rm dev ./vendor/bin/phpcs --standard=psr2 --ignore=vendor -n .`
Expected: both exit 0.

- [ ] **Step 6: Commit**

```bash
git add src/Keboola/Console/Command/FlowMigrationBatchRunner.php tests/FlowMigrationBatchRunnerTest.php
git commit -m "feat: add flow migration batch runner with concurrency window and polling"
```

---

### Task 5: Batch runner — skip rules and input deduplication

**Files:**
- Modify: `src/Keboola/Console/Command/FlowMigrationBatchRunner.php` (method `submitProject`, plus two `use` imports)
- Test: `tests/FlowMigrationBatchRunnerTest.php` (append methods)

**Interfaces:**
- Consumes: `Keboola\ManageApi\ClientException` (HTTP status via `getCode()`); everything from Task 4.
- Produces: final `submitProject()` behavior — skip order is: disabled/deleted → (token+clients) → no orchestrator configs → live migration job → createJob. No token is created for disabled/deleted projects.

- [ ] **Step 1: Write the failing tests**

Append to `tests/FlowMigrationBatchRunnerTest.php` (add `use Keboola\ManageApi\ClientException as ManageClientException;` and `use RuntimeException;` to the imports):

```php
    public function testSkipsDisabledProjectWithoutCreatingTokenOrJob(): void
    {
        $factory = new FakeFlowMigrationProjectClientsFactory(
            ['100' => ['id' => '100', 'name' => 'Off', 'isDisabled' => true]]
        );
        $runner = new FlowMigrationBatchRunner($factory, 10, 5, $this->sleepRecorder());

        $summary = $runner->run(['100'], true, new BufferedOutput(), $this->collector());

        // No ephemeral token may be created for a disabled project.
        $this->assertSame([], $factory->createClientsCalls);
        $this->assertSame(FlowMigrationProjectResult::STATUS_SKIPPED_DISABLED, $this->results[0]->status);
        $this->assertNull($this->results[0]->jobId);
        $this->assertSame(1, $summary['skippedDisabled']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame([], $this->sleeps);
    }

    public function testSkipsDeletedProjectOnManage404(): void
    {
        $factory = new FakeFlowMigrationProjectClientsFactory(
            ['100' => new ManageClientException('Project not found', 404)]
        );
        $runner = new FlowMigrationBatchRunner($factory, 10, 5, $this->sleepRecorder());

        $summary = $runner->run(['100'], true, new BufferedOutput(), $this->collector());

        $this->assertSame([], $factory->createClientsCalls);
        $this->assertSame(FlowMigrationProjectResult::STATUS_SKIPPED_DISABLED, $this->results[0]->status);
        $this->assertSame(1, $summary['skippedDisabled']);
    }

    public function testManageErrorOtherThan404MarksProjectFailed(): void
    {
        $factory = new FakeFlowMigrationProjectClientsFactory(
            ['100' => new ManageClientException('Internal error', 500)]
        );
        $runner = new FlowMigrationBatchRunner($factory, 10, 5, $this->sleepRecorder());

        $summary = $runner->run(['100'], true, new BufferedOutput(), $this->collector());

        $this->assertSame(FlowMigrationProjectResult::STATUS_ERROR, $this->results[0]->status);
        $this->assertSame('Internal error', $this->results[0]->error);
        $this->assertSame(1, $summary['failed']);
    }

    public function testSkipsProjectWithoutOrchestratorConfigurations(): void
    {
        $queueClient = new FakeJobQueueClient();
        $factory = new FakeFlowMigrationProjectClientsFactory(
            ['100' => self::enabledProject('100')],
            ['100' => self::clientsWith($queueClient, false)]
        );
        $runner = new FlowMigrationBatchRunner($factory, 10, 5, $this->sleepRecorder());

        $summary = $runner->run(['100'], true, new BufferedOutput(), $this->collector());

        // No queue API call and no job for a project with nothing to migrate.
        $this->assertSame(0, $queueClient->listJobsCalls);
        $this->assertSame([], $queueClient->createdJobs);
        $this->assertSame(FlowMigrationProjectResult::STATUS_SKIPPED_NO_ORCHESTRATIONS, $this->results[0]->status);
        $this->assertSame(1, $summary['skippedNoOrchestrations']);
    }

    public function testSkipsProjectWithLiveMigrationJob(): void
    {
        $queueClient = new FakeJobQueueClient(
            [],
            [],
            [FakeJobQueueClient::makeJob('existing-job', 'processing')]
        );
        $factory = new FakeFlowMigrationProjectClientsFactory(
            ['100' => self::enabledProject('100')],
            ['100' => self::clientsWith($queueClient)]
        );
        $runner = new FlowMigrationBatchRunner($factory, 10, 5, $this->sleepRecorder());

        $summary = $runner->run(['100'], true, new BufferedOutput(), $this->collector());

        $this->assertSame([], $queueClient->createdJobs);
        $this->assertSame(FlowMigrationProjectResult::STATUS_SKIPPED_JOB_RUNNING, $this->results[0]->status);
        $this->assertSame(1, $summary['skippedJobRunning']);
    }

    public function testDeduplicatesInputProjectIds(): void
    {
        $queueClient = new FakeJobQueueClient(
            [FakeJobQueueClient::makeJob('job-1', 'created')],
            ['job-1' => [FakeJobQueueClient::makeJob('job-1', 'success', 1)]]
        );
        $factory = new FakeFlowMigrationProjectClientsFactory(
            ['100' => self::enabledProject('100')],
            ['100' => self::clientsWith($queueClient)]
        );
        $runner = new FlowMigrationBatchRunner($factory, 10, 5, $this->sleepRecorder());

        $summary = $runner->run(['100', '100', '100'], true, new BufferedOutput(), $this->collector());

        $this->assertSame(1, $summary['attempted']);
        $this->assertCount(1, $queueClient->createdJobs);
        $this->assertCount(1, $this->results);
    }
```

- [ ] **Step 2: Run tests to verify the new ones fail**

Run: `docker compose run --rm dev ./vendor/bin/phpunit tests/FlowMigrationBatchRunnerTest.php`
Expected: `testDeduplicatesInputProjectIds` PASSES already (dedup shipped in Task 4's `run()`); the disabled/404/500 tests FAIL (`getProject` is never called, so the fake's clients-map miss makes them land in `failed`, not `skippedDisabled`); the no-orchestrations test FAILS (no configuration check exists yet); the live-job test PASSES already. Failing count: 4.

- [ ] **Step 3: Extend the implementation**

In `src/Keboola/Console/Command/FlowMigrationBatchRunner.php`, add one import
(`ListComponentConfigurationsOptions` is already imported since Task 4):

```php
use Keboola\ManageApi\ClientException as ManageClientException;
```

Replace the whole `submitProject()` method with:

```php
    /**
     * Runs the per-project pipeline up to job creation. Returns an in-flight slot on success,
     * or an immediately-final FlowMigrationProjectResult (skip or driver-side error).
     *
     * Order matters: the disabled/deleted check runs before any token is created, and the
     * configuration check runs before the queue guard so empty projects never appear in
     * customers' job history.
     *
     * @return FlowMigrationProjectResult|array{jobId: string, queueClient: JobQueueClient, startedAt: float, pollFailures: int}
     */
    private function submitProject(string $projectId, bool $force, OutputInterface $output)
    {
        try {
            $project = $this->clientsFactory->getProject($projectId);
        } catch (ManageClientException $e) {
            if ($e->getCode() === 404) {
                return new FlowMigrationProjectResult(
                    $projectId,
                    null,
                    FlowMigrationProjectResult::STATUS_SKIPPED_DISABLED,
                    null,
                    'project is deleted'
                );
            }

            return new FlowMigrationProjectResult(
                $projectId,
                null,
                FlowMigrationProjectResult::STATUS_ERROR,
                null,
                $e->getMessage()
            );
        }

        if (isset($project['isDisabled']) && $project['isDisabled']) {
            return new FlowMigrationProjectResult(
                $projectId,
                null,
                FlowMigrationProjectResult::STATUS_SKIPPED_DISABLED,
                null,
                'project is disabled'
            );
        }

        try {
            $clients = $this->clientsFactory->createProjectClients($projectId);

            $configurations = $clients->components->listComponentConfigurations(
                (new ListComponentConfigurationsOptions())
                    ->setComponentId(self::ORCHESTRATOR_COMPONENT_ID)
                    ->setIsDeleted(false)
            );
            if (count($configurations) === 0) {
                return new FlowMigrationProjectResult(
                    $projectId,
                    null,
                    FlowMigrationProjectResult::STATUS_SKIPPED_NO_ORCHESTRATIONS,
                    null,
                    'no keboola.orchestrator configurations'
                );
            }

            $liveJobs = $clients->queueClient->listJobs(
                (new ListJobsOptions())
                    ->setComponents([self::MIGRATION_COMPONENT_ID])
                    ->setStatuses(self::LIVE_JOB_STATUSES)
                    ->setLimit(1)
            );
            if ($liveJobs !== []) {
                return new FlowMigrationProjectResult(
                    $projectId,
                    null,
                    FlowMigrationProjectResult::STATUS_SKIPPED_JOB_RUNNING,
                    null,
                    'a keboola.flow-migration-tool job is already running in this project'
                );
            }

            $job = $clients->queueClient->createJob(new JobData(
                self::MIGRATION_COMPONENT_ID,
                null,
                [
                    'parameters' => [
                        'mode' => 'project',
                        'orchestrationIds' => [],
                        'skipBroken' => true,
                        'dryRun' => !$force,
                    ],
                ]
            ));
        } catch (Throwable $e) {
            return new FlowMigrationProjectResult(
                $projectId,
                null,
                FlowMigrationProjectResult::STATUS_ERROR,
                null,
                $e->getMessage()
            );
        }

        $this->writeLine($output, sprintf('Project %s: created job %s (%s)', $projectId, $job->id, $job->url));

        return [
            'jobId' => $job->id,
            'queueClient' => $clients->queueClient,
            'startedAt' => microtime(true),
            'pollFailures' => 0,
        ];
    }
```

Note: `listComponentConfigurations()` has no declared return type in the SDK (implicit mixed), so `count()` on it is phpstan-safe — the same access pattern as `DataAppOrchestratorTaskMigrator` uses. Update the existing Task 4 tests' fixtures if needed: they already provide `'100' => self::enabledProject('100')` in the `$projects` map, so `getProject()` succeeds there — no changes expected.

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose run --rm dev ./vendor/bin/phpunit tests/FlowMigrationBatchRunnerTest.php`
Expected: PASS (10 tests)

- [ ] **Step 5: Static analysis and code style**

Run: `docker compose run --rm dev composer phpstan && docker compose run --rm dev ./vendor/bin/phpcs --standard=psr2 --ignore=vendor -n .`
Expected: both exit 0.

- [ ] **Step 6: Commit**

```bash
git add src/Keboola/Console/Command/FlowMigrationBatchRunner.php tests/FlowMigrationBatchRunnerTest.php
git commit -m "feat: add skip rules for disabled, empty and already-migrating projects"
```

---

### Task 6: Batch runner — poll-failure tolerance and concurrency window verification

**Files:**
- Modify: `src/Keboola/Console/Command/FlowMigrationBatchRunner.php` (constant + method `pollInFlightJobs`)
- Test: `tests/FlowMigrationBatchRunnerTest.php` (append methods)

**Interfaces:**
- Consumes: everything from Tasks 4-5.
- Produces: `pollInFlightJobs()` tolerates up to 2 consecutive `getJob()` failures per job (a success resets the counter); the 3rd consecutive failure resolves the project as `STATUS_ERROR` with the job id preserved in the result. Constant `MAX_CONSECUTIVE_POLL_FAILURES = 3` (private).

- [ ] **Step 1: Write the failing tests**

Append to `tests/FlowMigrationBatchRunnerTest.php`:

```php
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
        $factory = new FakeFlowMigrationProjectClientsFactory(
            ['100' => self::enabledProject('100')],
            ['100' => self::clientsWith($queueClient)]
        );
        $runner = new FlowMigrationBatchRunner($factory, 10, 5, $this->sleepRecorder());

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
        $factory = new FakeFlowMigrationProjectClientsFactory(
            ['100' => self::enabledProject('100')],
            ['100' => self::clientsWith($queueClient)]
        );
        $runner = new FlowMigrationBatchRunner($factory, 10, 5, $this->sleepRecorder());

        $summary = $runner->run(['100'], true, new BufferedOutput(), $this->collector());

        $this->assertSame(FlowMigrationProjectResult::STATUS_ERROR, $this->results[0]->status);
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
        $factory = new FakeFlowMigrationProjectClientsFactory(
            ['100' => self::enabledProject('100'), '200' => self::enabledProject('200')],
            ['100' => $clients, '200' => $clients]
        );
        $runner = new FlowMigrationBatchRunner($factory, 1, 5, $this->sleepRecorder());

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
```

- [ ] **Step 2: Run tests to verify the new ones fail**

Run: `docker compose run --rm dev ./vendor/bin/phpunit tests/FlowMigrationBatchRunnerTest.php`
Expected: the two poll-failure tests FAIL (the `RuntimeException` from `getJob()` currently escapes `run()` uncaught). The concurrency test should PASS already (window logic shipped in Task 4) — it is the regression guard for this behavior.

- [ ] **Step 3: Extend the implementation**

In `src/Keboola/Console/Command/FlowMigrationBatchRunner.php`, add below `LIVE_JOB_STATUSES`:

```php
    // A transient Queue API outage must not fail a project instantly (the SDK already retries
    // 5xx internally), but an unbounded retry could hang the batch forever - so give up after
    // this many consecutive failed polls and leave the job to finish server-side.
    private const MAX_CONSECUTIVE_POLL_FAILURES = 3;
```

Replace the whole `pollInFlightJobs()` method with:

```php
    /**
     * @param array<string, array{jobId: string, queueClient: JobQueueClient, startedAt: float, pollFailures: int}> $inFlight
     * @param array{
     *     attempted: int,
     *     migrated: int,
     *     migratedWithWarning: int,
     *     skippedNoOrchestrations: int,
     *     skippedDisabled: int,
     *     skippedJobRunning: int,
     *     failed: int
     * } $summary
     * @param callable(FlowMigrationProjectResult): void $onProjectFinished
     */
    private function pollInFlightJobs(
        array &$inFlight,
        array &$summary,
        OutputInterface $output,
        callable $onProjectFinished
    ): void {
        foreach (array_keys($inFlight) as $projectId) {
            $slot = $inFlight[$projectId];
            try {
                $job = $slot['queueClient']->getJob($slot['jobId']);
            } catch (Throwable $e) {
                $inFlight[$projectId]['pollFailures']++;
                $this->writeLine($output, sprintf(
                    'Project %s: polling job %s failed (%d/%d): %s',
                    $projectId,
                    $slot['jobId'],
                    $inFlight[$projectId]['pollFailures'],
                    self::MAX_CONSECUTIVE_POLL_FAILURES,
                    $e->getMessage()
                ));
                if ($inFlight[$projectId]['pollFailures'] >= self::MAX_CONSECUTIVE_POLL_FAILURES) {
                    unset($inFlight[$projectId]);
                    $this->recordResult(
                        new FlowMigrationProjectResult(
                            $projectId,
                            $slot['jobId'],
                            FlowMigrationProjectResult::STATUS_ERROR,
                            null,
                            sprintf(
                                'polling gave up after %d consecutive failures, job may still be running: %s',
                                self::MAX_CONSECUTIVE_POLL_FAILURES,
                                $e->getMessage()
                            )
                        ),
                        $summary,
                        $output,
                        $onProjectFinished
                    );
                }
                continue;
            }

            $inFlight[$projectId]['pollFailures'] = 0;

            if (!$job->isFinished) {
                continue;
            }

            unset($inFlight[$projectId]);
            $durationSeconds = $job->durationSeconds ?? (int) round(microtime(true) - $slot['startedAt']);
            $this->recordResult(
                new FlowMigrationProjectResult(
                    $projectId,
                    $slot['jobId'],
                    $job->status,
                    $durationSeconds,
                    $this->extractJobError($job)
                ),
                $summary,
                $output,
                $onProjectFinished
            );
        }
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose run --rm dev ./vendor/bin/phpunit tests/FlowMigrationBatchRunnerTest.php`
Expected: PASS (13 tests)

- [ ] **Step 5: Static analysis and code style**

Run: `docker compose run --rm dev composer phpstan && docker compose run --rm dev ./vendor/bin/phpcs --standard=psr2 --ignore=vendor -n .`
Expected: both exit 0.

- [ ] **Step 6: Commit**

```bash
git add src/Keboola/Console/Command/FlowMigrationBatchRunner.php tests/FlowMigrationBatchRunnerTest.php
git commit -m "feat: tolerate transient poll failures and verify concurrency window"
```

---

### Task 7: The Symfony command, registration and input-parsing tests

**Files:**
- Create: `src/Keboola/Console/Command/MigrateOrchestrationsToFlow.php`
- Modify: `cli.php` (one `use` line + one `add()` line)
- Test: `tests/MigrateOrchestrationsToFlowTest.php`

**Interfaces:**
- Consumes: `FlowMigrationBatchRunner`, `FlowMigrationProjectClientsFactory`, `FlowMigrationProjectResult` (Tasks 1-6); `Keboola\ManageApi\Client`, `Keboola\ServiceClient\ServiceClient`.
- Produces: command `manage:migrate-orchestrations-to-flow` registered in `cli.php`. Private helpers (tested via reflection, matching the `MigrateDataAppsOrchestratorTasksTest` pattern): `hostnameSuffixFromUrl(string): ?string`, `parseProjectIdList(string): ?array`, `parseProjectIdsFile(string): ?array`.

- [ ] **Step 1: Write the failing tests**

Create `tests/MigrateOrchestrationsToFlowTest.php`:

```php
<?php

declare(strict_types=1);

namespace Keboola\Console\Tests;

use Keboola\Console\Command\MigrateOrchestrationsToFlow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class MigrateOrchestrationsToFlowTest extends TestCase
{
    /**
     * @return array<int, string>|string|null
     */
    private function invokePrivate(string $method, string $argument): array|string|null
    {
        $command = new MigrateOrchestrationsToFlow();
        $reflection = (new ReflectionClass($command))->getMethod($method);
        $reflection->setAccessible(true);

        /** @var array<int, string>|string|null $result */
        $result = $reflection->invoke($command, $argument);

        return $result;
    }

    #[DataProvider('provideUrls')]
    public function testHostnameSuffixFromUrl(string $url, ?string $expected): void
    {
        $this->assertSame($expected, $this->invokePrivate('hostnameSuffixFromUrl', $url));
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function provideUrls(): iterable
    {
        yield 'azure ne stack' => [
            'https://connection.north-europe.azure.keboola.com',
            'north-europe.azure.keboola.com',
        ];
        yield 'aws us stack' => ['https://connection.keboola.com', 'keboola.com'];
        yield 'trailing slash is fine' => ['https://connection.keboola.com/', 'keboola.com'];
        yield 'missing connection prefix' => ['https://queue.keboola.com', null];
        yield 'not a url' => ['not-a-url', null];
        yield 'bare connection host' => ['https://connection.', null];
    }

    #[DataProvider('provideProjectLists')]
    public function testParseProjectIdList(string $input, ?array $expected): void
    {
        $this->assertSame($expected, $this->invokePrivate('parseProjectIdList', $input));
    }

    /**
     * @return iterable<string, array{0: string, 1: array<int, string>|null}>
     */
    public static function provideProjectLists(): iterable
    {
        yield 'plain list' => ['1,2,3', ['1', '2', '3']];
        yield 'whitespace is trimmed' => ['1, 2 ,3', ['1', '2', '3']];
        yield 'duplicates are removed' => ['1,2,1', ['1', '2']];
        yield 'non-numeric entry invalidates the list' => ['1,foo', null];
        yield 'decimal is rejected' => ['1.2', null];
        yield 'negative is rejected' => ['-1', null];
        yield 'empty string is rejected' => ['', null];
    }

    #[DataProvider('provideProjectFiles')]
    public function testParseProjectIdsFile(string $contents, ?array $expected): void
    {
        $this->assertSame($expected, $this->invokePrivate('parseProjectIdsFile', $contents));
    }

    /**
     * @return iterable<string, array{0: string, 1: array<int, string>|null}>
     */
    public static function provideProjectFiles(): iterable
    {
        yield 'one id per line' => ["100\n200\n", ['100', '200']];
        yield 'blank lines and comments are ignored' => ["100\n\n# staging batch\n200\n", ['100', '200']];
        yield 'windows line endings' => ["100\r\n200\r\n", ['100', '200']];
        yield 'duplicates are removed' => ["100\n200\n100\n", ['100', '200']];
        yield 'non-numeric line invalidates the file' => ["100\nfoo\n", null];
        yield 'empty file is a valid empty list' => ['', []];
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose run --rm dev ./vendor/bin/phpunit tests/MigrateOrchestrationsToFlowTest.php`
Expected: FAIL — `Class "Keboola\Console\Command\MigrateOrchestrationsToFlow" not found`

- [ ] **Step 3: Write the command**

Create `src/Keboola/Console/Command/MigrateOrchestrationsToFlow.php`:

```php
<?php

declare(strict_types=1);

namespace Keboola\Console\Command;

use Keboola\ManageApi\Client as ManageClient;
use Keboola\ServiceClient\ServiceClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Batch driver for the automated keboola.orchestrator -> keboola.flow migration (AJDA-3117).
 * All migration logic lives in the keboola.flow-migration-tool component; this command only
 * creates and supervises its jobs across a list of projects on one stack.
 */
class MigrateOrchestrationsToFlow extends Command
{
    const ARG_TOKEN = 'token';
    const ARG_URL = 'url';
    const ARG_PROJECTS = 'projects';
    const OPT_FORCE = 'force';
    const OPT_PROJECTS_FILE = 'projects-file';
    const OPT_CONCURRENCY = 'concurrency';
    const OPT_POLL_INTERVAL = 'poll-interval';
    const OPT_REPORT = 'report';

    private const CSV_HEADER = ['projectId', 'jobId', 'status', 'durationSeconds', 'error'];
    private const CSV_DELIMITER = ';';

    protected function configure(): void
    {
        $this
            ->setName('manage:migrate-orchestrations-to-flow')
            ->setDescription(
                'Run the automated keboola.orchestrator -> keboola.flow migration for a batch of projects'
            )
            ->addArgument(self::ARG_TOKEN, InputArgument::REQUIRED, 'Manage API token')
            ->addArgument(
                self::ARG_URL,
                InputArgument::REQUIRED,
                'Stack URL, e.g. https://connection.north-europe.azure.keboola.com'
            )
            ->addArgument(
                self::ARG_PROJECTS,
                InputArgument::OPTIONAL,
                'Comma-separated project IDs, or @path/to/file with one ID per line'
            )
            ->addOption(
                self::OPT_FORCE,
                'f',
                InputOption::VALUE_NONE,
                'Run the real migration; without it jobs are created with dryRun: true'
            )
            ->addOption(
                self::OPT_PROJECTS_FILE,
                null,
                InputOption::VALUE_REQUIRED,
                'File with one project ID per line (alternative to @file in the argument)'
            )
            ->addOption(
                self::OPT_CONCURRENCY,
                null,
                InputOption::VALUE_REQUIRED,
                'Max migration jobs in flight at once',
                '10'
            )
            ->addOption(
                self::OPT_POLL_INTERVAL,
                null,
                InputOption::VALUE_REQUIRED,
                'Seconds between job status polls',
                '5'
            )
            ->addOption(
                self::OPT_REPORT,
                null,
                InputOption::VALUE_REQUIRED,
                'CSV report path (default: flow-migration-<stack>-<timestamp>.csv)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token = $input->getArgument(self::ARG_TOKEN);
        assert(is_string($token));
        $url = $input->getArgument(self::ARG_URL);
        assert(is_string($url));
        $force = (bool) $input->getOption(self::OPT_FORCE);

        $hostnameSuffix = $this->hostnameSuffixFromUrl($url);
        if ($hostnameSuffix === null) {
            $output->writeln(sprintf(
                'Invalid stack URL "%s": expected a URL like https://connection.keboola.com',
                $url
            ));
            return 1;
        }

        $projectIds = $this->resolveProjectIds($input, $output);
        if ($projectIds === null) {
            return 1;
        }

        $concurrency = $this->parsePositiveIntOption($input, self::OPT_CONCURRENCY);
        $pollInterval = $this->parsePositiveIntOption($input, self::OPT_POLL_INTERVAL);
        if ($concurrency === null || $pollInterval === null) {
            $output->writeln('Options --concurrency and --poll-interval must be positive integers');
            return 1;
        }

        $reportPath = $input->getOption(self::OPT_REPORT);
        if (!is_string($reportPath) || $reportPath === '') {
            $reportPath = sprintf('flow-migration-%s-%s.csv', $hostnameSuffix, date('Ymd-His'));
        }

        $output->writeln($force
            ? 'Running in FORCE mode: migration jobs run with dryRun: false.'
            : 'Running in dry-run mode: migration jobs run with dryRun: true. Use -f for the real migration.');
        $output->writeln('NOTE: even in dry-run mode a real keboola.flow-migration-tool job and a real'
            . ' ephemeral storage token are created in every eligible project.');
        $output->writeln(sprintf('Projects: %d, concurrency: %d, poll interval: %d s', count($projectIds), $concurrency, $pollInterval));
        $output->writeln(sprintf('Report: %s', $reportPath));
        $output->writeln('');

        $manageClient = new ManageClient(['url' => $url, 'token' => $token]);
        $serviceClient = new ServiceClient($hostnameSuffix);
        $clientsFactory = new FlowMigrationProjectClientsFactory($manageClient, $url, $serviceClient->getQueueUrl());

        $reportHandle = fopen($reportPath, 'a');
        if ($reportHandle === false) {
            $output->writeln(sprintf('Cannot open report file "%s" for writing', $reportPath));
            return 1;
        }
        if (ftell($reportHandle) === 0) {
            fputcsv($reportHandle, self::CSV_HEADER, self::CSV_DELIMITER, '"', '\\');
        }

        $runner = new FlowMigrationBatchRunner($clientsFactory, $concurrency, $pollInterval);
        $summary = $runner->run(
            $projectIds,
            $force,
            $output,
            function (FlowMigrationProjectResult $result) use ($reportHandle): void {
                fputcsv(
                    $reportHandle,
                    [
                        $result->projectId,
                        $result->jobId ?? '',
                        $result->status,
                        $result->durationSeconds !== null ? (string) $result->durationSeconds : '',
                        $result->error ?? '',
                    ],
                    self::CSV_DELIMITER,
                    '"',
                    '\\'
                );
                // Flush per row so an interrupted run still leaves an auditable report.
                fflush($reportHandle);
            }
        );
        fclose($reportHandle);

        $output->writeln('');
        $output->writeln(sprintf(
            "DONE\nProjects attempted: %d\nMigrated (job success): %d\nMigrated with warning: %d\n"
            . "Skipped (no orchestrations): %d\nSkipped (disabled/deleted): %d\n"
            . "Skipped (migration job already running): %d\nFailed: %d",
            $summary['attempted'],
            $summary['migrated'],
            $summary['migratedWithWarning'],
            $summary['skippedNoOrchestrations'],
            $summary['skippedDisabled'],
            $summary['skippedJobRunning'],
            $summary['failed']
        ));

        return $summary['failed'] > 0 ? 1 : 0;
    }

    /**
     * Derives the ServiceClient hostname suffix from a full connection URL, e.g.
     * "https://connection.north-europe.azure.keboola.com" -> "north-europe.azure.keboola.com".
     * Returns null when the URL does not look like a stack connection URL.
     */
    private function hostnameSuffixFromUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || !str_starts_with($host, 'connection.')) {
            return null;
        }
        $suffix = substr($host, strlen('connection.'));

        return $suffix === '' ? null : $suffix;
    }

    /**
     * Resolves the project ID list from exactly one source: the <projects> argument
     * (inline list or @file) or --projects-file. Prints an error and returns null otherwise.
     *
     * @return array<int, string>|null
     */
    private function resolveProjectIds(InputInterface $input, OutputInterface $output): ?array
    {
        $projectsArg = $input->getArgument(self::ARG_PROJECTS);
        $projectsFile = $input->getOption(self::OPT_PROJECTS_FILE);

        $hasArg = is_string($projectsArg) && $projectsArg !== '';
        $hasFileOption = is_string($projectsFile) && $projectsFile !== '';

        if ($hasArg === $hasFileOption) {
            $output->writeln(
                'Provide exactly one source of project IDs: the <projects> argument or --projects-file'
            );
            return null;
        }

        $projectIds = null;

        if ($hasArg) {
            assert(is_string($projectsArg));
            if (str_starts_with($projectsArg, '@')) {
                $projectsFile = substr($projectsArg, 1);
                $hasFileOption = true;
            } else {
                $projectIds = $this->parseProjectIdList($projectsArg);
            }
        }

        if ($hasFileOption) {
            assert(is_string($projectsFile));
            $contents = @file_get_contents($projectsFile);
            if ($contents === false) {
                $output->writeln(sprintf('Cannot read projects file "%s"', $projectsFile));
                return null;
            }
            $projectIds = $this->parseProjectIdsFile($contents);
        }

        if ($projectIds === null || $projectIds === []) {
            $output->writeln('Projects list is empty or contains a non-numeric ID');
            return null;
        }

        return $projectIds;
    }

    /**
     * @return array<int, string>|null null when any entry is not a plain non-negative integer
     */
    private function parseProjectIdList(string $raw): ?array
    {
        return $this->validateAndDeduplicate(array_map('trim', explode(',', $raw)));
    }

    /**
     * One ID per line; blank lines and lines starting with "#" are ignored.
     *
     * @return array<int, string>|null null when any remaining line is not a plain non-negative integer
     */
    private function parseProjectIdsFile(string $contents): ?array
    {
        $lines = preg_split('/\R/', $contents);
        $ids = [];
        foreach ($lines === false ? [] : $lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $ids[] = $line;
        }

        return $this->validateAndDeduplicate($ids);
    }

    /**
     * @param array<int, string> $ids
     * @return array<int, string>|null
     */
    private function validateAndDeduplicate(array $ids): ?array
    {
        foreach ($ids as $id) {
            if (!ctype_digit($id)) {
                return null;
            }
        }

        return array_values(array_unique($ids));
    }

    private function parsePositiveIntOption(InputInterface $input, string $name): ?int
    {
        $value = $input->getOption($name);
        if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }
}
```

- [ ] **Step 4: Register the command in cli.php**

In `cli.php`, add to the `use` block (after the `MigrateDataAppsOrchestratorTasks` line):

```php
use Keboola\Console\Command\MigrateOrchestrationsToFlow;
```

and after `$application->add(new MigrateDataAppsOrchestratorTasks());`:

```php
$application->add(new MigrateOrchestrationsToFlow());
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker compose run --rm dev ./vendor/bin/phpunit tests/MigrateOrchestrationsToFlowTest.php`
Expected: PASS (19 tests)

- [ ] **Step 6: Smoke-test the command wiring**

Run: `docker compose run --rm dev php cli.php list | grep migrate-orchestrations-to-flow`
Expected: one line with `manage:migrate-orchestrations-to-flow`.

Run: `docker compose run --rm dev php cli.php manage:migrate-orchestrations-to-flow some-token https://connection.keboola.com`
Expected: prints `Provide exactly one source of project IDs...` and exits with code 1 (verify with `echo $?` — note `docker compose run` propagates the container exit code).

Run: `docker compose run --rm dev php cli.php manage:migrate-orchestrations-to-flow some-token https://connection.keboola.com 1,foo`
Expected: prints `Projects list is empty or contains a non-numeric ID`, exit code 1.

- [ ] **Step 7: Static analysis and code style**

Run: `docker compose run --rm dev composer phpstan && docker compose run --rm dev ./vendor/bin/phpcs --standard=psr2 --ignore=vendor -n .`
Expected: both exit 0.

- [ ] **Step 8: Commit**

```bash
git add src/Keboola/Console/Command/MigrateOrchestrationsToFlow.php cli.php tests/MigrateOrchestrationsToFlowTest.php
git commit -m "feat: add manage:migrate-orchestrations-to-flow batch driver command"
```

---

### Task 8: README documentation and full quality gate

**Files:**
- Modify: `README.md` (new section under "Project manipulation", directly after the "Migrate data-apps orchestrator/flow tasks to data-app-control" section that ends at the line before `### Mass enablement of dynamic backends for multiple projects`)

**Interfaces:**
- Consumes: the finished command (Task 7).
- Produces: user-facing documentation; a fully green build.

- [ ] **Step 1: Add the README section**

Insert into `README.md` after the "Migrate data-apps orchestrator/flow tasks to data-app-control" section:

````markdown
### Migrate keboola.orchestrator configurations to keboola.flow

Batch driver for the automated `keboola.orchestrator` → `keboola.flow` migration
(see [AJDA-3117](https://linear.app/keboola/issue/AJDA-3117)). All migration logic lives in the
`keboola.flow-migration-tool` component; this command only creates one migration job per project
and supervises the batch. Safe to re-run with the same list: already-migrated orchestrations are
reported as skipped by the component, and projects with a live migration job are skipped here.

```
php cli.php manage:migrate-orchestrations-to-flow [-f|--force] <token> <url> [<projects>] \
    [--projects-file=PATH] [--concurrency=10] [--poll-interval=5] [--report=PATH]
```

Arguments:
- `token` (required): Manage API token.
- `url` (required): Stack URL, including `https://` (e.g. `https://connection.north-europe.azure.keboola.com`).
- `projects` (optional): Comma-separated project IDs (e.g. `1,7,146`), or `@path/to/file` with one ID
  per line (blank lines and `#` comments are ignored). Exactly one of `projects`/`--projects-file`
  must be given.

Options:
- `--force` / `-f`: Run the real migration. Without it, jobs are created with `dryRun: true`.
  **Note:** even without `--force` a real `keboola.flow-migration-tool` job and a real ephemeral
  storage token are created in every eligible project — on PAYGO stacks mind the billing.
- `--projects-file=PATH`: File with one project ID per line (alternative to `@file` in the argument).
- `--concurrency=N` (default 10): Max migration jobs in flight at once.
- `--poll-interval=N` (default 5): Seconds between job status polls.
- `--report=PATH` (default `flow-migration-<stack>-<timestamp>.csv`): CSV report path.

Behavior:
- For each project: skips disabled/deleted projects; creates an ephemeral 12h storage token
  (`canManageBuckets`, `canReadAllFileUploads`, component access to `keboola.orchestrator`,
  `keboola.flow`, `keboola.scheduler`, `keboola.flow-migration-tool`); skips projects with no
  `keboola.orchestrator` configurations (no empty jobs in customers' job history); skips projects
  where a `keboola.flow-migration-tool` job is already created/waiting/processing/terminating.
- Creates the migration job via `configData` (no stored configuration is left behind) with
  `parameters: {mode: "project", orchestrationIds: [], skipBroken: true, dryRun: <!force>}`.
- Keeps at most `--concurrency` jobs in flight, polls each job and refills the window as jobs finish.
- Appends a CSV row (`projectId;jobId;status;durationSeconds;error`) the moment each project
  resolves, so an interrupted run is still auditable. Every input project gets a row; skipped
  projects carry the skip reason in `status`/`error` and an empty `jobId`.
- A failing project never aborts the batch. Exit code is `1` if at least one project failed
  (job `error`/`terminated`/`cancelled` or a driver-side error), `0` otherwise.
- Final summary: projects attempted / migrated / migrated with warning / skipped (no
  orchestrations, disabled, job already running) / failed.
````

Note: the block above is fenced with four backticks only so it survives inside this plan file — in `README.md` itself use plain triple-backtick fences exactly like the surrounding sections.

- [ ] **Step 2: Full quality gate**

Run:
```bash
docker compose run --rm dev ./vendor/bin/phpcs --standard=psr2 --ignore=vendor -n .
docker compose run --rm dev composer phpstan
docker compose run --rm dev composer tests
```
Expected: all three exit 0; the full test suite passes (existing tests plus the 42 new ones).

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: document manage:migrate-orchestrations-to-flow command"
```

---

## Out of scope / hand-off

- The five "Must verify before the first live batch" items from AJDA-3117 (PAYGO billing exclusion
  for `keboola.flow-migration-tool`, notification-subscription visibility for ephemeral tokens,
  trigger `runWithTokenId` permission, token lifetime vs. queue wait, `keboola.flow` availability
  without a per-project feature) require live stack access — they are Ondrej's hand-off checklist,
  to be confirmed on a real project (start with a one-project batch) and recorded in a Linear comment.
- Per-orchestration migrated/skipped/failed counts in the CSV: not possible until
  `keboola/flow-migration-tool` exposes them in the job result (open question in the issue;
  potential follow-up there, not here).
