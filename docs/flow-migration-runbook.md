# Stack-wide orchestrator → flow migration runbook

How to migrate a batch of projects from legacy flows (`keboola.orchestrator`) to Conditional Flows
(`keboola.flow`) with `manage:migrate-orchestrations-to-flow`, verify the result with the audit
script and deal with the projects that do not go through.

All migration logic lives in the `keboola.flow-migration-tool` component. The command only creates
one migration job per project and supervises the batch (see [README](../README.md)). Re-running the
same project list is safe: already-migrated orchestrations are reported as skipped.

## 1. Prepare

- **A manage token for the stack.** Tokens are per stack and short-lived (hours to days). A token
  for one stack returns `401` / `storage.tokenInvalid` on another. Verify it before every batch:

  ```bash
  curl -sS -o /dev/null -w '%{http_code}\n' -H "X-KBC-ManageApiToken: $MANAGE_TOKEN" \
    https://connection.<stack>/manage/tokens/verify
  ```

- **A project id list**, one per line (`#` comments allowed). ~300 projects per batch works well.
- **notification-service with the deleted-branch fix.** Deleting a notification subscription whose
  `branch.id` points to a deleted branch used to answer `500`, which failed the orchestration. Fixed
  in keboola/notification-service#204 (`6646105`). On a single-tenant stack check the deployed
  version first: `curl https://notification.<stack>/` → `appVersion`.

### Pre-flight check

Drop projects with a **protected default branch** (SOX) from the list — the migration cannot run
there (the job is refused with `Role "none" without "canCreateJobs"…`, or the token creation with
`Action is restricted for your role "developer"`). Detect them by the `protected-default-branch`
feature, **not** by `type: sox` (some have `type: production`):

```bash
for P in $(cat batch1.txt); do
  curl -sS -H "X-KBC-ManageApiToken: $MANAGE_TOKEN" "https://connection.<stack>/manage/projects/$P" \
    | jq -r --arg p "$P" 'if .error then "\($p)\tNOT FOUND\t\(.error)"
        elif ([.features[]?] | index("protected-default-branch")) then "\($p)\tPROTECTED\t\(.name)\t\(.organization.name)"
        else empty end'
done
```

`NOT FOUND` projects can stay in the list — the command reports them as `skipped-disabled`.

## 2. Dry run, then the real run

```bash
php cli.php manage:migrate-orchestrations-to-flow <manageToken> <stackUrl> @batch1.txt \
    --concurrency=10 --report=flow-<stack>-batch1-dryrun.csv
```

```bash
php cli.php manage:migrate-orchestrations-to-flow -f <manageToken> <stackUrl> @batch1-run.txt \
    --concurrency=10 --report=flow-<stack>-batch1.csv
```

- Run through Docker (`docker compose run --rm app php cli.php …`) or directly with host PHP — the
  command only calls APIs. If Docker Desktop hangs on `compose run` (container created, never
  started), host PHP is the quickest way out.
- Without `-f` the jobs run with `dryRun: true`, but a real job and a real storage token are still
  created in every project — **on PAYGO projects a dry run costs credits too**.
- Feed the real run only the `success` rows of the dry run:

  ```bash
  awk -F';' '$3=="success"{print $1}' flow-<stack>-batch1-dryrun.csv > batch1-run.txt
  ```

- Let the jobs of an interrupted batch finish before re-running it — two concurrent migrations of
  one project can both create the same flows.
- The CSV report (`projectId;jobId;status;durationSeconds;error`) is written row by row. Statuses:
  `success`, `error`, `skipped-no-orchestrations`, `skipped-disabled`. Error messages can span
  several lines, so parse it as CSV (e.g. Python `csv`), not with `awk`.

## 3. Triage failures

| Error / symptom | Cause | What to do |
|---|---|---|
| `You don't have access to project <id>` | the manage token may read the project but not create a storage token in its organization | nothing was created; someone with access to the organization runs the migration, or the customer uses the one-click migration in the UI |
| `You do not have enough credits to run the job` | PAYGO project with a balance below zero | see [PAYGO projects](#4-paygo-projects-exhausted-credits) |
| `Role "none" without "canCreateJobs"…` / `Action is restricted for your role "developer"` | protected default branch (SOX) | missed by the pre-flight check; migrate by hand |
| job `success`, but `Failed to migrate "<name>": The 'runByToken' cannot be admin's token…` in the job result | a trigger of that orchestration runs under a user's admin token; a machine token may not create such a trigger | the orchestration is rolled back, the rest of the project migrated; the customer migrates it via the one-click migration in the UI |
| orchestration left enabled, no flow (audit `orchestrationsStillEnabled`) | a phase depends on more than one phase (fan-in), which Conditional Flows do not support | check `configuration.phases[].dependsOn` length > 1; the flow has to be reworked by hand |
| orchestration left enabled, flow **exists** | the flow already existed (customer migrated it earlier), so the re-run guard skipped it | check whether both run — see [legacy and flow both enabled](#legacy-orchestrator-and-flow-both-enabled) |

The full job result (the CSV truncates it) is in the Job Queue:
`curl -H "X-StorageApi-Token: <token>" https://queue.<stack>/jobs/<jobId> | jq -r .result.message`.

## 4. PAYGO projects (exhausted credits)

The job never starts and nothing is created. **Credits are purchased, not granted monthly** —
waiting does not help.

**Read the real balance** — only the billing service has it:

```bash
# 60 s storage token
curl -X POST -H "X-KBC-ManageApiToken: $MANAGE_TOKEN" -H "Content-Type: application/json" \
  -d '{"description":"credit check","expiresIn":60}' \
  https://connection.<stack>/manage/projects/<id>/tokens
```

```bash
curl -H "X-StorageApi-Token: <storageToken>" https://billing.<stack>/credits
```

→ `{"consumed": …, "remaining": -0.30, …}`

- Do **not** use `payAsYouGo.purchasedCredits` from `GET /manage/projects/{id}` — it is the
  cumulative sum of purchases, not a balance. Jobs can overspend, so a blocked project sits *below* zero.

**Top up** only when it was agreed (promo credit — nothing is billed; needs a superadmin manage token
or the `pay-as-you-go-credits-admin` feature):

```bash
curl -X POST -H "X-KBC-ManageApiToken: $MANAGE_TOKEN" -H "Content-Type: application/json" \
  -d '{"amount": 2, "description": "flow migration"}' \
  https://connection.<stack>/manage/projects/<id>/credits
```

- Amount: `ceil(-remaining) + 1` — clears the debt and leaves 1 credit for the job.
- **Re-read `remaining` after topping up.** Azure Marketplace projects with
  `marketplaceSubscription.state: "unsubscribed"` accept the grant but `remaining` stays 0.

## 5. Audit

```bash
MANAGE_TOKEN=<token> docs/flow-migration-audit.sh <stackUrl> <YYYY-MM-DD> migrated-batch1.txt
```

Audit the `success` rows of the real run, plus projects where only some orchestrations failed.
`<YYYY-MM-DD>` is the date the batch ran in the stack's local time — config `created` timestamps
carry the stack offset (e.g. `+0200`); flows created that day count as migrated. Scheduler and
notification URLs are derived from the stack URL. Per project it mints a 30 min token and checks
only the configs migrated on that date:

| Metric | Expected |
|---|---|
| read errors | none — any `err` line means the project was not audited; re-run it (connection timeouts are retried automatically) |
| `orchestrationsWithFlow` / `orchestrations` | equal, except orchestrations skipped as broken |
| `flowsCreatedOnDate` | number of orchestrations migrated in this batch |
| `orchestrationsStillEnabled` | only skipped orchestrations (migrated sources are disabled) — see [triage](#3-triage-failures) |
| `scheduleTokenSetsDiffer` | 0 — every flow schedule reuses the enabled source schedule's token; see [schedule differences](#schedule-differences) |
| `triggersMissing` | 0 — every source trigger has a flow clone with the same token, cool-down and tables |
| `triggersWithoutTablesNotCloned` | informational — source trigger whose tables were all deleted; it never fires and the API refuses to create it |
| `subscriptionsMissing` | 0 — every source subscription has a flow clone (same event, recipient, filters) |
| `warningSubscriptionsNotCloned` | informational — `job-succeeded-with-warning` is never cloned, flow does not emit it |
| `deadBranchSubscriptionsSkipped` | informational — source filtered on a deleted branch, skipped by the tool on purpose |
| `nestedStaleReferences` | 0 — no orchestrator/flow task still calls `keboola.orchestrator/<id>` when a flow `<id>` exists |
| `nestedStaleInDisabledLegacy` | informational — stale call inside a disabled legacy orchestrator (e.g. migrated in an earlier run); it never runs |

So `sourceTriggers = flowTriggers + triggersWithoutTablesNotCloned` and `sourceSubscriptions = flowSubscriptions + warningSubscriptionsNotCloned + deadBranchSubscriptionsSkipped`
on a clean run. Subscription comparison ignores `job.component.id` (flipped on clone) and `branch.id`
(added by the notification service), and compares filter values as strings. A disabled flow whose
orchestrator twin is enabled (hand-reverted migration) is not counted as a stale nested target.
Anything non-zero is listed under `=== problems ===`; per-project results stay in
`flow-audit-<timestamp>.jsonl`.

### Schedule differences

The tool activates a flow schedule only when the `keboola.scheduler` **config** says `enabled` and a
live schedule exists. The Scheduler API keeps its own **live** state, and **the UI shows the live
state**. When the two disagree, the audit reports a token difference. Check whether the live
schedule actually creates jobs:

```bash
curl -H "X-StorageApi-Token: <token>" https://scheduler.<stack>/schedules/<scheduleId> | jq '.executions[0:3]'
```

- `job_id: null` in every execution — the schedule fires but creates no job (e.g. its config was
  disabled or deleted). Same effective behaviour after the migration, **no action**.
- real `job_id`s — the legacy schedule really runs, so the customer expects the flow to run too.
  Enable the flow-side scheduler config and activate it with the source schedule's token:

  ```bash
  # 1. set configuration.schedule.state = "enabled" on the flow-side keboola.scheduler config
  # 2. activate it, reusing the source schedule's token
  curl -X POST -H "X-StorageApi-Token: <token>" -H "Content-Type: application/json" \
    -d '{"configurationId":"<flowSchedulerConfigId>","sourceConfigurationId":"<sourceSchedulerConfigId>"}' \
    https://scheduler.<stack>/schedules/migrate
  ```

### Legacy orchestrator and flow both enabled

When the flow already existed before the run, the orchestrator is left as it was. Compare the
enabled live schedules of both and their last jobs:

```bash
curl -H "X-StorageApi-Token: <token>" https://scheduler.<stack>/schedules \
  | jq -r --arg c <id> '.[] | select(.target.configurationId==$c) | "\(.target.componentId) \(.schedule.state) \(.schedule.cronTab)"'
```

If both have an enabled schedule, the job runs twice — the customer has to disable the legacy
orchestrator. If the legacy one has no enabled schedule, nothing runs twice.

## 6. Report

One comment per batch, compact tables first, prose only for causes and decisions:

| Result | Projects |
|---|---|
| attempted / migrated / no orchestrations / deleted | |
| not migrated — no access / credits / protected default branch | |

| Audit | Value |
|---|---|
| projects audited / read errors | |
| orchestrations / with flow / created in this batch | |
| orchestrations still on legacy | |
| schedules: configs / token sets differ | |
| triggers: source / flow / missing | |
| subscriptions: source / flow / warning not cloned / missing | |
| nested references to flow / stale | |

List projects without access by name — `GET /manage/projects/{id}` still works for them:
`Project ID | Project name | Organization`.
