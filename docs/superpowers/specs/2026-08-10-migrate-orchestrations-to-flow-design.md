# Design: manage:migrate-orchestrations-to-flow (AJDA-3117)

## Goal

Add a `cli-utils` command that drives the automated `keboola.orchestrator` → `keboola.flow`
migration across a batch of projects on one stack. The command is a **driver only**: it creates
and supervises `keboola.flow-migration-tool` jobs in customer projects. All migration logic lives
in the component (`keboola/flow-migration-tool`); nothing from it is reimplemented here.

Per-stack migrations (AJDA-3119 Azure NE, AJDA-3120 GCP US) then become a single supervised run.

## Verified SDK facts (read from `vendor/`, versions from `composer.lock`)

All packages needed are already installed — **no composer changes required**:

| Package | Version | What we use |
|---|---|---|
| `keboola/job-queue-api-php-client` | 5.2.0 | `Client::__construct(string $publicApiUrl, string $storageToken, array $options = [])`; `createJob(JobData): DTO\Job`; `getJob(string $jobId): DTO\Job`; `listJobs(ListJobsOptions): array` (elements are `DTO\Job`); `JobData::__construct(string $componentId, ?string $configId = null, array $configData = [], string $mode = 'run', ...)`; `ListJobsOptions::setComponents(array)/setStatuses(array<JobStatuses>)/setLimit(int)`; `JobStatuses` enum (`CREATED`, `WAITING`, `PROCESSING`, `TERMINATING`, ... `SUCCESS`, `ERROR`, `WARNING`, `TERMINATED`, `CANCELLED`); `DTO\Job` readonly props: `id`, `status`, `isFinished`, `durationSeconds`, `result`, `url` |
| `keboola/service-client` | 1.5.1 | `new ServiceClient(string $hostnameSuffix)`; `getQueueUrl()` → `https://queue.<suffix>` |
| `keboola/kbc-manage-api-php-client` | v7.1.1 | `getProject($id)`; `createProjectStorageToken($projectId, array $params)` — generic POST pass-through, accepts `expiresIn`, `canManageBuckets`, `canReadAllFileUploads`, `componentAccess`, `description` |
| `keboola/storage-api-client` | v18.7.0 | `Components::listComponentConfigurations(ListComponentConfigurationsOptions)` with `setComponentId()`/`setIsDeleted(false)` |

Notes:
- `Client::waitForJobCompletion()` exists but blocks on a single job — unusable for a concurrency
  window; we poll with `getJob()` ourselves.
- `listJobs()` has no typed return; the batch runner never touches list elements — the running-job
  guard only needs `$jobs !== []` (avoids the `$job['id']`-on-DTO trap present in
  `QueueMassTerminateJobs`).
- `DTO\Job::fromApiResponse()` requires many keys — fakes in tests will construct results through
  it with a full response fixture, or the fake client returns pre-built `Job` instances.

## Command

```
php cli.php manage:migrate-orchestrations-to-flow [-f|--force] <token> <url> [<projects>]
    [--projects-file=PATH] [--concurrency=10] [--poll-interval=5] [--report=PATH]
```

### Arguments

| Argument | Type | Description |
|---|---|---|
| `token` | REQUIRED | Manage API token |
| `url` | REQUIRED | Stack URL incl. scheme, e.g. `https://connection.north-europe.azure.keboola.com` |
| `projects` | OPTIONAL | Comma-separated project IDs, or `@path/to/file` (one ID per line) |

`token` and `url` come first to stay compatible with `manage:call-on-stacks`
(`AllStacksIterator` builds `<command> <manageToken> <stackHost> <rest>`).

### Options

| Option | Default | Description |
|---|---|---|
| `-f`, `--force` | off | Real migration (`parameters.dryRun: false`). Without it, jobs run with `dryRun: true` |
| `--projects-file=PATH` | — | Alternative to `@file` in the argument |
| `--concurrency=N` | 10 | Max migration jobs in flight |
| `--poll-interval=N` | 5 | Seconds between poll sweeps |
| `--report=PATH` | `flow-migration-<hostnameSuffix>-<Ymd-His>.csv` | CSV report path |

The component's `parameters.migrate.*` sub-flags are **not** exposed: the command always requests
a full migration and relies on the component defaults.

**Important semantic difference from the usual cli-utils dry-run:** even without `--force` the
command creates a *real* `keboola.flow-migration-tool` job (with `dryRun: true`) in every eligible
project and creates a real ephemeral storage token. The command prints a prominent notice about
this at startup, and the README documents it (relevant for PAYGO billing — see hand-off items).

### Input resolution and validation

- Exactly one source of project IDs must be given: the `projects` argument (inline list or
  `@file`) or `--projects-file`. Both, or neither → error message + exit 1.
- File format: one ID per line; blank lines and lines starting with `#` are ignored.
- Every ID must pass `ctype_digit()`; any invalid entry → error naming the offending value, exit 1.
- Duplicates are removed (first occurrence wins) so a re-run with a sloppy list cannot double-submit.
- `--concurrency` ≥ 1, `--poll-interval` ≥ 1, both integers; otherwise exit 1.
- `url` must parse to a host beginning with `connection.`; the hostname suffix for `ServiceClient`
  is that host minus the `connection.` prefix (e.g. `north-europe.azure.keboola.com`). This keeps
  the issue-mandated full-URL argument *and* resolves the Queue API URL via `keboola/service-client`
  (no `connection` → `queue` string replace on the URL).

## Architecture

Four small classes in `src/Keboola/Console/Command/` (PSR-0: namespace
`Keboola\Console\Command`, path = file name), plus registration in `cli.php`:

```
MigrateOrchestrationsToFlow          (Symfony Command — thin shell)
  ├─ parses/validates input, resolves project ID list
  ├─ builds ManageApi\Client, ServiceClient, FlowMigrationProjectClientsFactory
  ├─ opens the CSV report (append mode, header if new/empty) and wires the
  │  per-result callback: CSV row + progress line to stdout
  ├─ runs FlowMigrationBatchRunner
  └─ prints final summary, returns exit code (1 if any project failed)

FlowMigrationBatchRunner             (plain class — ALL batch logic, unit-tested)
  ├─ per-project pipeline (skip rules, job submission)
  ├─ concurrency window + polling loop
  └─ emits one FlowMigrationProjectResult per input project via callback,
     returns aggregate summary counts

FlowMigrationProjectClientsFactory   (plain class — the only network seam)
  ├─ getProject(string $projectId): array            (Manage API)
  └─ createProjectClients(string $projectId): FlowMigrationProjectClients
       creates the ephemeral storage token, returns Components + JobQueueClient
       bound to that token

FlowMigrationProjectClients          (tiny readonly DTO: Components + JobQueueClient)
FlowMigrationProjectResult           (tiny readonly DTO: projectId, jobId, status,
                                      durationSeconds, error + isFailed())
```

Rationale: the repo's testable-logic pattern (`DataAppOrchestratorTaskMigrator` +
`FakeComponents`) extended one step — because per-project clients are created with per-project
ephemeral tokens, the runner cannot receive clients directly; it receives a factory. Tests
subclass the factory and the SDK clients without calling parent constructors (exactly how
`FakeComponents` already works). No interfaces — the codebase does not use them.

### Ephemeral token (created per project, before any Storage/Queue call)

```php
$manageClient->createProjectStorageToken($projectId, [
    'description' => 'AJDA-3117 keboola.orchestrator -> keboola.flow migration (batch driver)',
    'expiresIn' => 43200, // 12 h: job may wait in queue and runs long; expiry mid-migration is worse than a short-lived privileged token
    'canManageBuckets' => true,
    'canReadAllFileUploads' => true,
    'componentAccess' => [
        'keboola.orchestrator',
        'keboola.flow',
        'keboola.scheduler',
        'keboola.flow-migration-tool',
    ],
]);
```

Broad rights are deliberate (trigger/notification migration touches project-level resources);
the token expires on its own, no cleanup step.

### Per-project pipeline (inside the runner, at submission time)

1. `getProject()` — `isDisabled` → result `skipped-disabled`. Manage API 404 (deleted project)
   → also `skipped-disabled` (issue counts disabled+deleted together). Other Manage errors →
   `error` result (project failed, batch continues).
2. Create ephemeral token + per-project clients via the factory. Failure → `error` result.
3. `listComponentConfigurations(componentId: keboola.orchestrator, isDeleted: false)` —
   empty → `skipped-no-orchestrations` (no job created; avoids hundreds of empty jobs in
   customers' job history).
4. Queue guard: `listJobs(components: [keboola.flow-migration-tool], statuses: [CREATED,
   WAITING, PROCESSING, TERMINATING], limit: 1)` — non-empty → `skipped-job-running`.
   (`TERMINATING` added on top of the issue's three: a terminating job may still be executing
   migration writes, and skipping it strictly reduces overlap risk.)
5. `createJob(new JobData('keboola.flow-migration-tool', configData: [...]))` — via `configData`,
   so no stored configuration is left behind in the project:

   ```json
   {
     "parameters": {
       "mode": "project",
       "orchestrationIds": [],
       "skipBroken": true,
       "dryRun": <!force>
     }
   }
   ```

   (`orchestrationIds: []` and `skipBroken: true` are required by the component's config
   definition in `project` mode.) Job enters the in-flight window with its own `JobQueueClient`.

### Concurrency window + polling

```
pending  = input project queue
inFlight = projectId → {jobId, queueClient, startedAtWallClock, consecutivePollFailures}

while pending not empty or inFlight not empty:
    fill: while |inFlight| < concurrency and pending: submit next
          (skips/errors resolve immediately → result callback, do not occupy a slot)
    if inFlight empty: continue
    sleep(pollInterval)                      # injected callable, no-op in tests
    for each inFlight job: getJob()
        finished → result callback (terminal status), free the slot
        poll exception → consecutivePollFailures++; after 3 consecutive failures
          mark project error ("job <id> still running server-side, polling gave up"),
          free the slot; a successful poll resets the counter
```

- `durationSeconds` in the result: `Job->durationSeconds` when the API provides it, otherwise
  wall-clock from submission.
- No per-job or global timeout (operator supervises; token bounds the run at 12 h anyway).
- No signal handling — the incrementally-appended CSV already makes an interrupted run auditable.
- `sleep` is injected as a `callable` (default `sleep(...)`) so unit tests run instantly and can
  assert poll cadence.

### CSV report

- Path from `--report`, default `flow-migration-<hostnameSuffix>-<Ymd-His>.csv` in cwd.
- Opened in append mode; header written only when the file is new or empty.
- Written with `fputcsv(..., separator: ';')` — error messages containing `;`/newlines get quoted
  correctly; no new dependency.
- Header + one row **per input project** (including skips, for a complete audit of the list):

  ```
  projectId;jobId;status;durationSeconds;error
  ```

  `status` ∈ job terminal status (`success`, `warning`, `error`, `terminated`, `cancelled`)
  or `skipped-disabled` | `skipped-no-orchestrations` | `skipped-job-running` | `error`.
  `jobId` is empty for rows without a job. Rows are appended the moment each project resolves.

### Progress output and summary

- One stdout line per event (`[HH:ii:ss]` prefix): job submitted (with job id + job URL), project
  skipped (with reason), project finished (status + duration), poll warnings.
- Final summary: attempted / migrated (job `success`; `warning` reported as migrated-with-warning)
  / skipped-no-orchestrations / skipped-disabled / skipped-job-running / failed
  (job `error`|`terminated`|`cancelled`, or driver-side error).
- **Exit code 1 if at least one project failed, else 0.** A failing project never aborts the batch.

### Re-runs

Re-running the same list is the intended recovery path: the component's
`AlreadyMigratedValidator` reports already-migrated orchestrations as `skipped`, and the queue
guard skips projects with a live migration job. No resume state is kept by the driver.

## Error handling summary

| Failure | Behavior |
|---|---|
| Invalid input (IDs, options, both/neither project sources) | message + exit 1, nothing executed |
| `getProject` 404 | `skipped-disabled` (deleted) |
| `getProject` other error, token creation, config listing, guard, `createJob` failure | `error` result for that project, batch continues |
| Poll failure | tolerated 3 consecutive times per job, then `error` result; job keeps running server-side |
| Job terminal `error`/`terminated`/`cancelled` | `failed` in summary, exit code 1 |

## Testing

`tests/FlowMigrationBatchRunnerTest.php` + fakes (PSR-4 `Keboola\Console\Tests\`):

- `FakeJobQueueClient extends JobQueueClient\Client` — constructor override (no parent call, same
  trick as `FakeComponents`), records `createJob` calls, scripted `getJob` status sequences
  (e.g. `processing, processing, success`), scripted `listJobs` guard responses, can throw on
  demand for poll-failure tests.
- `FakeFlowMigrationProjectClientsFactory extends FlowMigrationProjectClientsFactory` — scripted
  projects (disabled / deleted / erroring), records `createProjectClients` calls (asserts no token
  is created for disabled projects), returns `FlowMigrationProjectClients` built from
  `FakeComponents` (reused as-is for the orchestrator-config listing) + `FakeJobQueueClient`.

Scenarios (assert emitted results, summary counts, recorded API calls, sleep-callable cadence):
1. happy path — N projects, jobs created with exact `configData` (incl. `dryRun` true/false by
   force flag), results in completion order;
2. all three skip rules, each without a job being created (and without a token for disabled);
3. concurrency window never exceeds the limit and refills as jobs finish;
4. one project's job ends `error` → batch continues, summary flags failure;
5. driver-side error (token creation throws) → `error` result, batch continues;
6. poll failures: 2 consecutive then success → no failure; 3 consecutive → `error` result;
7. duplicate project IDs in input are submitted once.

The Symfony command shell itself is not unit-tested (repo convention); `composer phpcs`,
`composer phpstan` (level 9), `composer tests` via `docker compose` must pass.

## Documentation

README.md, section "Project manipulation", after "Migrate data-apps orchestrator/flow tasks…":
usage line, Arguments/Options, Behavior — including the explicit warning that dry-run still
creates real jobs and real ephemeral tokens in customer projects, the CSV format, re-run safety,
and exit-code semantics.

## Decisions made without the reporter (recorded, with rationale)

1. **`url` stays a full connection URL; hostname suffix is derived** (strip `connection.` from the
   parsed host) — keeps `manage:call-on-stacks` compatibility and the issue's signature while
   using `ServiceClient` for the Queue URL.
2. **`TERMINATING` added to the guard statuses** — a terminating job may still write; strictly safer.
3. **CSV gets a row for every input project including skips** — makes the report a complete audit;
   the issue's "resolvable job ID for every project" holds for every project that got a job.
4. **`warning` terminal status counts as migrated** (reported distinctly) — the component finished;
   exit code stays 0. The CSV carries the raw status either way.
5. **Poll failures tolerated 3× consecutively, then the project is marked failed** — an unbounded
   retry could hang the batch forever; the client already retries 5xx internally 3×.
6. **Duplicates deduplicated, `#`-comment lines allowed in the projects file** — hundreds-of-IDs
   lists are hand-assembled; cheap robustness.
7. **No interactive confirmation** — unlike the `all` mode of the data-apps migration command,
   the blast radius here is always an explicit project list.
8. **`fputcsv` over `keboola/csv`** — append semantics with header-once needs a plain handle;
   no new dependency.
9. **English spec/plan/docs** — repo and git content are English per user's git conventions.

## Out of scope (YAGNI)

- No `all` projects mode — per-stack batches are driven from explicit lists (AJDA-3119/3120).
- No exposure of `parameters.migrate.*` sub-flags.
- No resume file/state beyond the CSV; no signal handling; no per-job timeout.
- No per-orchestration counts in the CSV — the component does not expose them in the job result
  (issue open question; possible follow-up in `keboola/flow-migration-tool`).

## Hand-off items (require live stack access — not implementation work)

The five "Must verify before the first live batch" items from AJDA-3117 (billing exclusion on
PAYGO, notification listing visibility for ephemeral tokens, trigger `runWithTokenId` permission,
token lifetime vs. queue wait, `keboola.flow` availability without a per-project feature) plus
acceptance criterion 9 must be confirmed by Ondrej on a live project and recorded in a Linear
comment. The command itself is designed so these checks can run as a one-project batch first.
