# CLI UTILS

[![Build](https://github.com/keboola/cli-utils/actions/workflows/build.yaml/badge.svg)](https://github.com/keboola/cli-utils/actions/workflows/build.yaml)

Assorted CLI utils

# Usage and development

### Running in Docker

`docker run --rm -it keboola/cli-utils php ./cli.php <commands>`

### Running locally

1. Clone the repo
2. Init .env file `cp .env.dist .env` (actual values are not needed unless you want to run `manage:mass-project-remove-expiration`)
3. Build the image `docker compose build app`
4. Run a command `docker compose run --rm app php cli.php <command>`

### Development
1. Clone the repo
2. Init .env file `cp .env.dist .env` (actual values are not needed unless you want to run `manage:mass-project-remove-expiration`)
3. Build the image `docker compose build dev`
4. [optional] Install dependencies `docker compose run --rm dev composer install`
5. Update the code
6. Run a command `docker compose run --rm app php cli.php <command>`


# Commands documentation
In the following sections you can find documentation for all the commands available in this package.

## Features
### Bulk Project Add Feature
Adds a project feature to multiple projects

```
php cli.php manage:projects-add-feature [-f|--force] <token> <url> <featureName> <projects>
```
By in argument `<projects>` you can
- select projects to add the feature to by specifying project IDs  separated by a comma (e.g. `1,2,3,4`)
  -  `manage:projects-add-feature  <token> <url> <featureName> 1,2,3` 
- OR run the script for ALL projects in the stack by passing `all` argumetn
  -  `manage:projects-add-feature  <token> <url> <featureName> all`

Note: the feature has to exist before calling, and it has to be type of `project`

### Add Feature on all projects in Organization
Adds a project feature to all projects in selected organizations

```
php cli.php manage:organizations-add-feature [-f|--force] <token> <url> <featureName> <organizations>
```
By in argument `<projects>` you can
- select projects to add the feature to by specifying organization IDs separated by a comma (e.g. `1,2,3,4`)
  -  `manage:organizations-add-feature  <token> <url> <featureName> 1,2,3`

Note: the feature has to exist before calling, and it has to be type of `project`

### Conditionally Add Feature
Adds a target feature to projects based on whether they have a given condition feature.

```
php cli.php manage:projects-add-feature-conditionally [-f|--force] <token> <url> <condition-feature> <target-feature> [--maintainer-id=ID] [--organization-id=ID] [--project-id=ID] [--condition-mode=present|absent]
```

The scope is the whole stack by default. You can narrow it down with one of the mutually exclusive options:
- `--project-id` – process a single project
- `--organization-id` – process all projects of one organization
- `--maintainer-id` – process all projects of all organizations of one maintainer

The `<condition-feature>` argument accepts one feature or a comma-separated list of features (e.g. `batch1,batch0`). The `--condition-mode` option controls how they are evaluated (default `present`):
- `present` – add the target feature only to projects that have **all** of the condition features
- `absent` – add the target feature only to projects that have **none** of the condition features

In both modes projects that already have the target feature are skipped, and disabled projects are skipped.

Examples:
- `manage:projects-add-feature-conditionally <token> <url> new-billing new-ui` – dry run, add `new-ui` to projects that have `new-billing`
- `manage:projects-add-feature-conditionally -f <token> <url> new-billing new-ui --organization-id=123`
- `manage:projects-add-feature-conditionally -f <token> <url> legacy-billing new-ui --condition-mode=absent` – add `new-ui` to projects that do NOT have `legacy-billing`
- `manage:projects-add-feature-conditionally -f <token> <url> batch1,batch0 batch2 --condition-mode=absent` – add `batch2` to projects that have neither `batch1` nor `batch0`

Note: both the condition and target features have to exist before calling, and have to be of type `project`. The options `--maintainer-id`, `--organization-id` and `--project-id` are mutually exclusive.

### Bulk Project Remove Feature
Removes a project feature from multiple projects

```
php cli.php manage:projects-remove-feature [-f|--force] <token> <url> <featureName> <projects>
```
By in argument `<projects>` you can
- select projects to add the feature to by specifying project IDs  separated by a comma (e.g. `1,2,3,4`)
    -  `manage:projects-remove-feature <token> <url> <featureName> 1,2,3`
- OR run the script for ALL projects in the stack by passing `all` argument
    -  `manage:projects-remove-feature <token> <url> <featureName> all`

### Add a feature to project templates
You can add a project feature to all the project templates available on the stack

`php cli.php manage:add-feature-to-templates [-f|--force] <token> <url> <featureName> <featureTitle> [<featureDesc>]`

**This command supports dry-run. Add the `-f` flag if you want to submit the changes**

## Workspaces and sandboxes

### Read this before deleting anything

A component-created workspace has three separate things attached to it:

1. the **workspace record** in Storage metadata,
2. the **backend user / schema** (e.g. the Snowflake user and its schema), and
3. the **parent component configuration** that created it.

Deleting the workspace (`deleteWorkspace`) removes 1 and 2 and leaves the configuration alone.
Deleting the **configuration** (delete twice = move to trash, then purge) cascades and takes its
workspaces with it. Which of the two a command does is the single most important thing about it:

- For sandbox-type components (`keboola.sandboxes`) the configuration **is** the sandbox, so
  deleting it is the correct cleanup.
- For transformation components (`keboola.snowflake-transformation` and friends) the configuration
  is **the user's transformation code**. Deleting it destroys user work, not a leaked resource.

Every command in this section is dry-run by default and needs `--force`/`-f` to change anything.
Always read the dry-run output first, and check the "Destroys" line below before you pass `-f`.

There is also a fourth state worth naming, because it needs its own tool: the workspace record can
already be gone from Storage metadata while the **backend user survives** (a failed or half-finished
delete). Nothing that works through workspaces or editor sessions can see those - see
`--ignore-backend-errors` on `storage:delete-orphaned-workspaces`.

### Which command should I use?

| What you have / want to do | Command |
| --- | --- |
| Just **see what is there** in an organization, as a CSV report | `manage:describe-organization-workspaces` |
| A list of workspaces and you want **what state each one is in** | `manage:check-project-workspaces-state` |
| An **explicit list of workspace IDs** to delete, with safety guards | `manage:delete-project-workspaces-by-id` |
| Leaked workspaces of **one component, older than a cutoff**, in one project | `storage:delete-orphaned-workspaces` |
| The same, across **whole organizations** | `manage:delete-organization-workspaces` |
| Workspaces whose **owner is no longer in the project**, in one project | `storage:delete-ownerless-workspaces` |
| The same, across a **whole organization** | `manage:delete-organization-ownerless-workspaces` |
| An explicit CSV list of `projectId,WORKSPACE_schema` (legacy) | `manage:mass-delete-project-workspaces` |
| Workspace already gone from metadata but the **backend user survives** | `storage:delete-orphaned-workspaces --ignore-backend-errors` |

Rules of thumb:

- **Start with a read-only command.** Use `manage:describe-organization-workspaces` when you want to
  survey a whole organization, and `manage:check-project-workspaces-state` when you already have a
  list of workspaces and need to know what is still live. Both report the component, creator and
  state you need in order to pick the right command and the right filter below.
- **Prefer `manage:delete-project-workspaces-by-id` when you have a concrete list.** It is the only
  deletion command with layered guards (login type, expected schema, and a check that a configuration
  owns exactly the one workspace you named), and it deletes the workspace without touching the
  configuration unless you explicitly ask. `manage:mass-delete-project-workspaces` predates it, needs
  an interactively pasted token per project, and always purges the configuration - prefer the by-id
  command unless you specifically need schema-based matching.
- The two `*-orphaned-*` commands and the two `*-ownerless-*` commands are each **the same selection
  logic at two different scopes** (single project vs. organization). Pick by scope; the behaviour is
  otherwise the same.
- "Orphaned" is a misnomer inherited from the command name: those commands do **not** detect whether
  anything is actually orphaned. They select purely by *component + age*. You are responsible for
  choosing a component where that is a safe proxy.
- `manage:mass-delete-project-workspaces` resolves your schemas through **editor sessions**, so it
  cannot find a workspace that has no session. Its "not found" list is the important part of its
  output, not an afterthought.

### Describe Connection Workspaces for an organization
Read-only. Writes a CSV describing all Connection workspaces in an organization, across all dev
branches of every project. Use it to decide what to delete and with which command.

```
php ./cli.php manage:describe-organization-workspaces <manage-token> <organization-id> <output-file> [<hostname-suffix>]
```
Arguments:
- manage-token (required) Manage API token.
- organization-id (required) Target organization ID.
- output-file (required) Path of the CSV to write.
- hostname-suffix (optional, default: keboola.com) Connection host suffix (e.g. eu-central-1.keboola.com).

Destroys: nothing, this command is read-only.

The output CSV has the header:
```
projectId,projectName,branchId,branchName,componentId,configurationId,creatorEmail,activeUser,createdDate,snowflakeSchema,readOnlyStorageAccess
```
`activeUser` is `true` when the workspace's creator email still matches a current user of the project,
which is the same signal the `*-ownerless-*` commands act on.

### Check the state of a list of workspaces
Read-only. Takes a list of workspaces you already care about (typically left over from an earlier
cleanup) and reports, per row, whether the workspace is still live, whether its configuration is
live / in the trash / gone, and when its configuration last ran a job. Each row gets a suggested
follow-up command, so this is the triage step before any of the deletion commands.

```
php ./cli.php manage:check-project-workspaces-state <manage-token> <source-file> <output-file> [<hostname-suffix>]
```
Arguments:
- manage-token (required) Manage API token (super admin); used to mint a short-lived Storage token per project.
- source-file (required) CSV **without header**, either two or four columns per line: `projectId,workspaceSchema` or `projectId,workspaceSchema,componentId,configurationId`. Schemas must start with `WORKSPACE_`.
- output-file (required) Path of the CSV report to write.
- hostname-suffix (optional, default: keboola.com) Connection host suffix.

Destroys: nothing, this command is read-only.

Each row is classified as one of:

| status | meaning | suggested follow-up |
| --- | --- | --- |
| `live` | the workspace still exists | `manage:delete-project-workspaces-by-id` |
| `config_in_trash` | configuration sits in the trash, so its workspace and backend user still exist | purge the configuration from the trash |
| `config_live_workspace_gone` | configuration is live but this workspace is not - backend user likely orphaned | investigate |
| `purged_or_orphan` | neither workspace nor configuration found; a backend user may survive | drop the backend user (`storage:workspace:drop-failed-workspaces-from-metadata`) |
| `not_live_no_config_ref` | not live and no component/configuration reference to check against | investigate |
| `access_denied` | the manage token cannot reach the project | grant access and re-run |

The output CSV header is:
```
projectId,workspaceSchema,componentId,configurationId,status,suggestedAction,workspaceId,branchId,branchName,loginType,liveComponentId,liveConfigurationId,configState,configName,configCreated,configCreator,lastJobStatus,lastJobCreated,lastJobEnd,note
```

Behavior:
- Groups the input by project and mints one short-lived Storage token per project.
- Indexes live workspaces by schema across **all** dev branches, then lists live and trashed
  configurations of every referenced component, **per branch**. Branches hold independent copies of a
  configuration under the same id, so the states are deliberately not merged across branches - a
  configuration live in one branch and trashed in another is reported in the workspace's own branch,
  and the other branches go into the `note` column.
- Looks up the most recent job of each configuration through the Queue API and reports it in the
  `lastJob*` columns, which is usually the deciding signal for whether a configuration is still in
  use. It first runs a sanity query for *any* job in the project and warns when that comes back
  empty, so that blank `lastJob*` columns are not misread as "never used".
- Prints a per-status summary at the end.

### Delete specific workspaces by ID
Deletes individual workspaces named explicitly by ID, across multiple projects. This is the
list-driven deletion command to reach for: it does not guess, and it refuses anything that does not
match what you described.

```
php ./cli.php manage:delete-project-workspaces-by-id [-f|--force] [--any-login-type] [--with-configuration] <manage-token> <source-file> [<hostname-suffix>]
```
Arguments:
- manage-token (required) Manage API token (super admin); used to mint a short-lived Storage token per project.
- source-file (required) CSV **without header**, two or three columns per line: `projectId,workspaceId[,expectedSchema]`. When `expectedSchema` is present the workspace's actual schema must match it or the row is skipped.
- hostname-suffix (optional, default: keboola.com) Connection host suffix.

Options:
- `--force` / `-f` Actually delete. Without it only reports.
- `--any-login-type` Also delete workspaces that do not use password login (key-pair etc.). **Use with care** - by default only password-login (`LEGACY_SERVICE`) workspaces are touched.
- `--with-configuration` Delete the whole parent configuration (trash + purge) instead of just the workspace. Refuses any configuration that owns a workspace other than the one you listed.

Destroys: the **workspace only** by default. With `--with-configuration` the parent configuration and
therefore everything it owns.

Behavior:
- Indexes every workspace of each project across all dev branches by workspace ID, and reports rows
  it cannot find rather than failing silently.
- Skips, with a message and its own counter, any row whose schema does not match `expectedSchema`,
  or whose login type is not a password login unless `--any-login-type` is given.
- With `--with-configuration` it first determines whether the configuration is live or already in the
  trash, because that decides whether it needs one delete call (purge) or two (trash, then purge).
  A live configuration is checked against the API's own list of its workspaces; a trashed one, whose
  workspaces can no longer be listed, is checked against the project's live workspaces that still
  reference it. Either way the configuration must own **exactly** the one workspace you listed.
- A configuration that is neither live nor in the trash is reported as an orphaned workspace and
  skipped, with a hint to delete it without `--with-configuration`.
- If a purge is refused with `storage.components.cannotDeleteConfiguration`, that row is reported as
  **failed**, not deleted: the configuration is probably still in the trash and its workspace and
  backend user still exist, so it needs a re-check and a re-run.
- Prints a final summary counting deletions, failures, not-found rows and each skip reason
  separately.

### Delete Orphaned Workspaces command
Deletes workspaces of **one component** that were created **before a cutoff date**, in a single
project, across all its dev branches. The intended use case is workspaces left behind by failed
transformation jobs.

```
php ./cli.php storage:delete-orphaned-workspaces [-f|--force] [-i|--ignore-backend-errors] [-m|--manage-token=TOKEN] <storage-token> <orphan-component> [<hostname-suffix>] [<until-date>]
```
Arguments:
- storage-token (required) Storage API token for the target project.
- orphan-component (required) A **single** component ID matched exactly (e.g. `keboola.snowflake-transformation`). Pass `""` to match workspaces with an empty/blank component.
- hostname-suffix (optional, default: keboola.com) Connection host suffix.
- until-date (optional, default: `-1 month`) Cutoff as a `strtotime` expression; only workspaces created **before** it are selected.

Note the argument order: `hostname-suffix` comes **before** `until-date`.

Options:
- `--force` / `-f` Actually delete. Without it only reports.
- `--ignore-backend-errors` / `-i` Instead of deleting each workspace through the Storage API, collect the matched workspace IDs and drop them via the Manage API command `storage:workspace:drop-failed-workspaces-from-metadata`. Requires `--manage-token`. Use this for workspaces whose backend user survived a failed delete. This **replaces** the normal per-workspace delete, it is not additive.
- `--manage-token` / `-m` Super-admin Manage API token, required by `--ignore-backend-errors`.

Destroys: the **workspace only**. The parent configuration is left untouched.

Behavior:
- Iterates all dev branches of the project and lists workspaces in each.
- Selects a workspace when `component` equals `orphan-component` **and** `created` is before `until-date`.
- Prints every workspace it skips together with the reason, so a dry run is auditable.
- Reports how many of the total workspaces found were deleted.

### Delete Orphaned Workspaces in Organization command
The organization-scoped counterpart of `storage:delete-orphaned-workspaces`: same component + age
selection, but it walks every project of one or more organizations and mints its own short-lived
Storage token per project from a Manage token.

```
php ./cli.php manage:delete-organization-workspaces [-f|--force] [-c|--component=ID] [-g|--component-group=NAME] [-d|--until-date=DATE] [-H|--hostname-suffix=SUFFIX] <manage-token> <organization-ids>
```
Arguments:
- manage-token (required) Manage API token.
- organization-ids (required) **Comma-separated** list of organization IDs (e.g. `123,456`).

Options:
- `--force` / `-f` Actually delete. Without it only reports.
- `--component` / `-c` A single component ID matched exactly, or `""` for empty/blank components.
- `--component-group` / `-g` A predefined group instead of a single component. Available: `transformations` (`keboola.snowflake-transformation`, `keboola.legacy-transformation`, `transformation`).
- `--until-date` / `-d` (default `-1 month`) Cutoff as a `strtotime` expression; only workspaces created before it are selected.
- `--hostname-suffix` / `-H` (default `keboola.com`) Connection host suffix.

Exactly one of `--component` / `--component-group` is required; passing both is an error.
Note that unlike the project-scoped variant, the component, cutoff and host suffix are **options,
not positional arguments**.

Destroys: the **workspace only**. The parent configuration is left untouched.

Behavior:
- For each organization, lists its projects and creates a temporary Storage token per project
  (skipping projects the token cannot access, with a warning).
- Iterates all dev branches per project and applies the same selection as the project-scoped command.
- Prints per-project, per-organization and final summaries, including a breakdown of skipped
  workspaces by component.

### Delete Sandboxes/Workspaces that were created by no longer active token id
Deletes sandboxes and workspaces in a project whose **owner is no longer an active user of the
project**. Use it after people leave a project or an organization.

```
php ./cli.php storage:delete-ownerless-workspaces [-f|--force] [--includeShared] <storage-token> [<hostname-suffix>]
```
Arguments:
- storage-token (required) Storage API token for the target project.
- hostname-suffix (optional, default: keboola.com) Connection host suffix.

Options:
- `--force` / `-f` Actually delete. Without it only reports.
- `--includeShared` Also delete shared sessions and shared sandbox configurations. Skipped by default.

Destroys: the **parent configuration** (trash + purge), which cascades to its workspace and backend
user. This is the correct behaviour for sandboxes, where the configuration is the sandbox itself.

Behavior:
- Lists the project's tokens to build the set of active user IDs and active token IDs.
- **SQL sessions:** lists editor-service sessions and selects those whose `userId` is not an active
  user. Deletes the session's configuration twice (trash, then purge). If the purge is refused with
  `storage.components.cannotDeleteConfiguration`, deletes the editor session instead.
- **Python/R sandboxes:** lists apps from the sandboxes service and selects those whose
  `keboola.sandboxes` configuration was created by a token that is no longer active. Queues a
  `keboola.sandboxes` delete job for each; if queueing fails it falls back to deleting the app
  directly and then purging its configuration, so nothing is left half-deleted.
- Note the asymmetry: sessions are matched by **user ID** (stable), sandbox configurations by
  **creator token ID**, which is re-issued whenever a user leaves and rejoins a project. A user who
  left and came back keeps their SQL sessions but loses their old Python/R sandboxes.

### Delete ownerless Sandboxes/Workspaces across an organization
The organization-scoped counterpart of `storage:delete-ownerless-workspaces`. The selection and
deletion logic is identical; this variant iterates every project of one organization and mints its
own short-lived Storage token per project, and prints a per-project summary at the end.

```
php ./cli.php manage:delete-organization-ownerless-workspaces [-f|--force] [--includeShared] <manage-token> <organization-id> [<hostname-suffix>]
```
Arguments:
- manage-token (required) Manage API token.
- organization-id (required) A **single** numeric organization ID.
- hostname-suffix (optional, default: keboola.com) Connection host suffix.

Options:
- `--force` / `-f` Actually delete. Without it only reports.
- `--includeShared` Also delete shared sessions and shared sandbox configurations. Skipped by default.

Destroys: the same as the project-scoped variant - the **parent configuration** (trash + purge),
cascading to its workspace and backend user.

### Delete multiple project workspaces across projects
Deletes workspaces listed explicitly in a CSV, across multiple projects, matched by **workspace
schema name**.

```
php cli.php manage:mass-delete-project-workspaces [-f|--force] <stack-suffix> <source-file>
```
Arguments:
- stack-suffix (required) Stack host suffix (e.g. `keboola.com`, `eu-central-1.keboola.com`).
- source-file (required) CSV **without header**, exactly two columns per line: `<projectId>,<WORKSPACE_schema>`. Example:
  ```
  12345,WORKSPACE_111111111
  98765,WORKSPACE_222222222
  ```

Options:
- `--force` / `-f` Actually delete. Without it only reports.

Destroys: the **parent configuration** (trash + purge), which cascades to its workspace and backend
user. It does not call `deleteWorkspace` at all.

Behavior:
- Builds a `projectId => [schemas]` map and validates that every schema starts with `WORKSPACE_`.
- For each project it **prompts interactively on STDIN** for that project's Storage token, so tokens
  are never kept in a file. This makes the command unsuitable for large unattended batches.
- Resolves each schema through the project's **editor-service sessions**, then deletes the matching
  session's configuration twice (trash, then purge), tolerating
  `storage.components.cannotDeleteConfiguration` on the purge.
- Because the lookup goes through editor sessions, **any workspace without a session cannot be
  found**. Those schemas are printed at the end as "not found (are deleted or need to be deleted
  manually)" - read that list, it is where the real leftovers end up.
- Targeted at Snowflake (SNFLK) workspaces.

## Project manipulation

### Notify Projects

Prepare input `data.csv`:
```
"projectId","notificationTitle","notificationMessage"
"9","Test notification","Test notification content"
```

Command execution:
```
cat data.csv |  php cli.php storage:notify-projects MANAGETOKEN
```


### Force Unlink Shared and Linked Buckets

List all buckets in the project and force-unlink those that are both shared and linked. By default, the command runs in dry-run mode and only reports what would be unlinked. Use the `--force` flag to actually perform the unlinking.

```
php cli.php storage:force-unlink-shared-buckets [--force|-f] <storageToken> <url>
```
Arguments:
- `storageToken` (required): Storage API token for the target project.
- `url` (required): Stack URL, including `https://`.

Options:
- `--force` / `-f`: Actually perform the unlinking. Without this flag, the command only reports what would be unlinked (dry-run).

Behavior:
- Lists all buckets in the project.
- For each bucket, checks if it is both shared and linked.
- In dry-run mode, lists the buckets that would be unlinked.
- With `--force`, unlinks each shared and linked bucket and confirms the action.
- Prints a summary of unlinked or would-be-unlinked buckets.

### Migrate data-apps orchestrator/flow tasks to data-app-control
Migrate orchestration/flow tasks that start a data app via the legacy `keboola.data-apps` component so they use
`keboola.data-app-control` instead (see [AJDA-2445](https://linear.app/keboola/issue/AJDA-2445)). Skips tasks whose
`keboola.data-apps` parameters look unlike the "start app" shape used in practice (e.g. `create`/`delete`/`terminate`),
so those are never silently mistransformed. Safe to re-run: already-migrated tasks are skipped.

```
php cli.php manage:migrate-data-apps-orchestrator-tasks [-f|--force] <token> <url> <projects>
```
Arguments:
- `token` (required): Manage API token.
- `url` (required): Stack URL, including `https://`.
- `projects` (required): Comma-separated project IDs (e.g. `1,7,146`), or `all` to run on every project on the stack.

Options:
- `--force` / `-f`: Actually perform the migration. Without this flag, the command only reports what would change (dry-run).

Behavior:
- For each target project, creates a temporary Storage API token via the Manage API and lists all configurations of
  both `keboola.orchestrator` (legacy orchestrations) and `keboola.flow` (next-gen conditional flows) - these are two
  separate components on stacks with conditional flows enabled, not variants of the same one.
- Scans each configuration's tasks for ones pointing at `keboola.data-apps` (both inline `configData` and `configId`
  references to a saved `keboola.data-apps` configuration).
- Rewrites matching tasks to `keboola.data-app-control` with `parameters.appId` set from the resolved app ID
  (`configId` references are flattened into inline `configData` rather than creating a new saved configuration).
- Tasks that reference a `keboola.data-apps` config that couldn't be resolved (missing/inaccessible `configId`
  reference, or a malformed/non-scalar app id) are reported separately from deliberately-skipped, expected task
  shapes (e.g. `create`/`delete`/`terminate`) - the former needs manual follow-up, the latter doesn't.
- With `--force`, updates the configuration via the Storage API with a `changeDescription`. Without it, only reports
  what would be migrated.
- Running with `<projects>` set to `all` together with `--force` asks for interactive confirmation first, as a
  safety net against an accidental stack-wide mutation.
- Prints a summary: projects checked/disabled/errored, configurations scanned/touched, tasks migrated/skipped
  (unsupported vs. unresolvable).

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
- For each project: skips disabled/deleted projects; creates an ephemeral 1h storage token with full
  project rights (`canManageBuckets`, `canManageTokens`, `canReadAllFileUploads`, `canPurgeTrash`) so
  the component cannot be short of a permission mid-migration; skips projects with no
  `keboola.orchestrator` configurations (no empty jobs in customers' job history).
- Creates the migration job via `configData` (no stored configuration is left behind) with
  `parameters: {mode: "project", orchestrationIds: [], skipBroken: true, dryRun: <!force>}`.
- Keeps at most `--concurrency` jobs in flight, polls each job and refills the window as jobs finish.
  A transient poll failure is tolerated up to 3 consecutive times per job; after that the project is
  reported as failed and the job is left to finish server-side (its job ID stays in the report).
- Appends a CSV row (`projectId;jobId;status;durationSeconds;error`) the moment each project
  resolves, so an interrupted run is still auditable. Every input project gets a row; skipped
  projects carry the skip reason in `status`/`error` and an empty `jobId`. Re-running with the same
  `--report` path appends to the existing file without repeating the header.
- A failing project never aborts the batch. Exit code is `1` if at least one project failed
  (job `error`/`terminated`/`cancelled` or a driver-side error), `0` otherwise.
- Final summary: projects attempted / migrated / migrated with warning / skipped (no
  orchestrations, disabled) / failed.
- Re-running the same project list is the intended recovery path: the component reports
  already-migrated orchestrations as skipped. The command does **not** check for a migration job
  already running in the project, so before re-running an interrupted batch let the jobs it already
  created finish - two concurrent migrations of one project can both create the same flows.

### Mass enablement of dynamic backends for multiple projects
Prerequisities: https://keboola.atlassian.net/wiki/spaces/KB/pages/2135982081/Enable+Dynamic+Backends#Enable-for-project

- Create a manage token.
- Prepare an input file (e.g. "projects") with ID of the projects you want to enable dynamic backends for.
    ```
    1234
    5678
    9012
    3456
    ```

- Run the mass migration command
    ```
    php cli.php manage:mass-project-enable-dynamic-backends [--force-new-trans -f] <manage_token> <kbc_url> <file_with_projects> 
    ```
The command will do the following for every projectId in the source file:
- check if the project has project feature `queuev2`. If not, project migration fails
- check if the project has project feature `new-transformations-only`. If not, it offers to add it. If the `--force-new-trans` is provided, it won't ask, but it will do it automatically
- run `storage:tmp:enable-workspace-snowflake-dynamic-backend-size` storage command on the stack for the selected project. It reports error if it fails.


### Mass project extend expiration:

Prepare an input file "extend.txt" in the following format: 
```
123-EU
579-US
579-NE
```

Prepare env:
```
export KBC_MANAGE_TOKEN_US=XXXXX
export KBC_MANAGE_TOKEN_EU=XXXXX
export KBC_MANAGE_TOKEN_NE=XXXXX
```

Run command:

`php cli.php manage:mass-project-remove-expiration extend.txt 0`

Use number of days or 0 as show to remove expiration completely. By default, it's dry-run. Override with `-f` parameter.

### Bulk Delete Projects

Delete all projects specified by project IDs using the Manage API. By default, the command runs in dry-run mode and only reports what would be deleted. Use the `--force` flag to actually perform deletions.

```
php cli.php manage:delete-projects [-f|--force] <token> <url> <projects>
```
Arguments:
- `token` (required): Manage API token.
- `url` (required): Stack URL, including `https://`.
- `projects` (required): Comma-separated list of project IDs to delete (e.g. `1,7,146`).

Options:
- `--force` / `-f`: Actually delete the projects. Without this flag, the command only reports what would be deleted (dry-run).

Behavior:
- For each project ID, checks if the project exists and is not already disabled.
- In dry-run mode, lists the projects that would be deleted.
- With `--force`, deletes each project and confirms deletion.
- Prints a summary of disabled, deleted, and failed projects.
- If run without `--force`, reminds the user that it was a dry run.


### Purge deleted projects
Purge already deleted projects (remove residual metadata, optionally ignoring backend errors) using a Manage API token.

```
php cli.php storage:deleted-projects-purge [--ignore-backend-errors] <manageToken> <stackUrl> [--ignore-backend-errors] <projectIds>
```

### Set data retention for multiple projects
Set data retention days for specific projects listed in a CSV piped via STDIN.

```
cat retention.csv | php cli.php storage:set-data-retention <manageToken> [--url=<stackConnectionUrl>]
```
Input CSV header must be exactly:
```
projectId,dataRetentionTimeInDays
```
Behavior:
- For each project row calls updateProject with provided retention days.
- Logs success or error per project; ends with "All done.".
- Default URL: https://connection.keboola.com (override with --url).

Example retention.csv:
```
projectId,dataRetentionTimeInDays
12345,7
67890,30
```

### Update data retention for all projects on the stack
Bulk update data retention days for ALL projects on a stack (optionally dry-run first) – only affects projects that have Snowflake backend.

```
php cli.php storage:update-data-retention [-f|--force] <manageToken> <stackConnectionUrl> <dataRetentionTimeInDays>
```
Arguments:
- manageToken (required) Manage API token.
- stackConnectionUrl (required) Full Connection URL, e.g. https://connection.keboola.com.
- dataRetentionTimeInDays (required) Target retention value.

Options:
- --force / -f Actually apply changes. Without it runs dry-run and only reports.

Behavior:
- Iterates maintainers -> organizations -> projects.
- Skips disabled projects (counts them separately).
- Skips projects without Snowflake backend (counts separately).
- (Force) updates remaining projects, otherwise states it "would update".
- Prints final summary: maintainers, orgs, disabled projects, non-snowflake projects, updated/would-update, errors.

### Reactivate Scheduler schedules after SOX migration
Recreate (reactivate) Scheduler schedules after SOX migration by deleting each existing `keboola.scheduler` configuration in Scheduler API and creating a new schedule referencing it.

```
php cli.php storage:reactivate-schedules [-f|--force] <storageToken> [<stack-suffix>]
```
Arguments:
- storageToken (required) Project maintainer (PM) Storage API token with access to all scheduler configs.
- stack-suffix (optional, default: keboola.com) e.g. eu-central-1.keboola.com.

Options:
- --force / -f Actually perform DELETE + POST operations. Without it logs planned actions (dry-run).

Behavior:
- Lists all non-deleted configurations of component `keboola.scheduler`.
- For each: (force) DELETE /configurations/{id} on scheduler service, then POST /schedules {configurationId} to recreate active schedule.

## Jobs

### Load Queue Jobs Lineage events into Marqueez

```
export STORAGE_API_TOKEN=<token>
php cli.php storage:lineage-events-export <marquezUrl> [<connectionUrl>] [--limit=100] [--job-names-configurations]
```

Loads last N _(default 100)_ jobs into Marquez tool. Export has two modes:
- default - jobs are identified by job IDs
- with `--job-names-configurations` option - job are identified by component and configuration IDs


### Mass job termination command
This command can be used to terminate all jobs in a project in specified state (`created`, `waiting` or `processing`).

- Create a Storage token

- Run the command
    ```
    php ./cli.php queue:terminate-project-jobs <storage-token> <connection-url> <job-status>
    ```

# Utils
## Bulk operation on multiple stacks

If you want to run your command on multiple stacks, you can predefine stacks and `manageTokens` in `http-client` files and then use `manage:call-on-stacks` command to run it on all the defined stack. How?
1. make a copy of `http-client.env.json.dist` and `http-client.private.env.json.dist` and remove the `.dist` part.
2. Fill stack URLs and corresponding `manageToken`
3. Run the command in following form `php cli.php manage:call-on-stacks <target command> "<params of your commnand>"`
    - `<target command>` has to support arguments `token` and `url` in this order 
    - `"<params of your commnand>"` contain all the params for your target command but without `token` and `url` arguments. This part has to be quotet.
      - E.g. I want to run `manage:add-feature-to-templates <token> <url> featureXX featureTitle featureDesc -f`
      - So I call `php cli.php manage:call-on-stacks manage:add-feature-to-templates "featureXX featureTitle featureDesc -f"`
4. The command iterates over the stacks and asks your confirmation if you want to run the `taget command` there. You can skip it


## Set Storage Backend for Organization
This command is rather specific to BYODB snowflake backend migration.
You can use it to set all projects of an organization to use a storage backend.

- Run the command
    ```
    php ./cli.php manage:set-organization-storage-backend [--force/-f] <manage-token> <organization-id> <storage-backend-id> <hostname-suffix> 
    ```

## Delete Storage Backend
Delete one or more storage backends from a stack by their IDs. Dry-run by default.

- Run the command
    ```
    php ./cli.php manage:delete-backend [--force/-f] <manage-token> <backend-ids> <stack-url>
    ```
Arguments:
- manage-token (required) Manage API token for the stack.
- backend-ids (required) Comma-separated list of storage backend IDs to delete (e.g. `123,456,789`).
- stack-url (required) Full stack URL, e.g. https://connection.keboola.com.

Options:
- --force / -f Actually remove the backends. Without it the command runs in dry-run mode and only lists what would be removed.

Behavior:
- Lists all storage backends on the stack.
- For each requested ID: skips it (with a message) when no such backend exists; otherwise prints the backend host and owner and (with --force) removes it via the Manage API.

## Reset Workspace passwords for projects in an organization
This command is rather specific to BYODB snowflake backend migration.
It resets all legacy (not keypair type) Snowflake workspace passwords for all projects in an organization.

- Run the command
    ```
    php ./cli.php manage:reset-organization-workspace-passwords [--force/-f] <manage-token> <organization-id> <snowflake-hostname> <hostname-suffix> 
    ```
It prints `command-01k3m9p324cae95c48rr23rqvh` for each project, you can track the progress in Datadog.

## Set a maintenance mode for the organization
This command can be used to enable/disable all projects in an organization
The usecase for this command is to set all projects into maintenance at once for byodb migration.
It will perform a dry run unleass the `--force/-f` option is applied.

Arguments:
- Manage Token *required*
- OrganizationId *required*
- Maintenance Mode *required* (on or off)
- Hostname sUffix *optional* (default: keboola.com)
- Disable reason *optional* 
- Estimated end time *optional*

- Run the command
    ```
    php ./cli.php manage:set-organization-maintenance-mode [--force/-f] <manage-token> <organization Id> <on/off> <hostname-suffix> <reason> <estimatedEndTime> 
    ```
  
## Remove user from all projects in an organization
Remove a user (by email) from all projects in an organization. Dry-run by default.

```
php cli.php manage:remove-user-from-organization-projects [-f|--force] <manageToken> <organizationId> <userEmail> [<hostnameSuffix>]
```
Arguments:
- manageToken (required) Manage API token with access to the organization.
- organizationId (required) Target organization ID.
- userEmail (required) Email of the user to remove.
- hostnameSuffix (optional, default: keboola.com) Connection stack suffix.

Options:
- --force / -f Actually remove the user. Without it only logs projects where removal would happen.

Behavior:
- Fetches the organization and user, iterates its projects and lists project users.
- If the user is a member, logs removal (and performs it if forced).
- Prints final count of affected projects.


# License

MIT licensed, see [LICENSE](./LICENSE) file.
