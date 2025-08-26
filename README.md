# CLI UTILS

[![Build](https://github.com/keboola/cli-utils/actions/workflows/build.yaml/badge.svg)](https://github.com/keboola/cli-utils/actions/workflows/build.yaml)

Assorted CLI utils

# Usage and development

### Running in Docker

`docker run --rm -it keboola/cli-utils php ./cli.php <commands>`

### Running locally and local development
1. Clone the repo
2. Build the image `docker build dev`
3. Init .env file `cp .env.dist .env` (actual values are not needed unless you want to run `manage:mass-project-remove-expiration`)
4. Install dependencies `docker run --rm dev composer install`
5. Run commands 
   1. From docker: `docker run --rm dev php ./cli.php <command>`
   2. Locally using local php bin: `php ./cli.php <command>`
   3. In Container: `docker run --rm dev bash` -> `php ./cli.php <command>`

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

### Delete Orphaned Workspaces command
This command can be used to delete all the workspaces in a project that were made for componentIds in the `component-list` argument
and that were created before the `until-date` argument.
The usecase for this command is to remove workspaces not cleaned after transformation failures.
It will perform a dry run unleass the `--force/-f` option is applied.

- Create a Storage token

- Run the command
    ```
    php ./cli.php storage:delete-orphaned-workspaces [--force/-f] <storage-token> <component-list> <untile-date> <hostname-suffix> 
    ```

### Delete Orphaned Workspaces in Organization command
This command can be used to delete all workspace in an organization that were made for componentIds in the `component` argument
and that were created before the `until-date` argument.

The usecase for this command is to remove workspaces that were not cleaned after transformation failures.
It will perform a dry run unleass the `--force/-f` option is applied.

- Create a Storage token

- Run the command
    ```
    php ./cli.php manage:delete-organization-workspaces [--force/-f] <manage-token> <organization-id> <component> <untile-date> <hostname-suffix> 
    ```

### Describe Connection Workspaces for an organization
This command takes an output file argument and writes out a csv describing all connection workspaces in an organisation.
The output file has header:
```
'projectId',
'projectName',
'branchId',
'branchName',
'componentId',
'configurationId',
'creatorEmail',
'createdDate',
'snowflakeSchema',
'readOnlyStorageAccess'
```
Arguments:
- Manage Token *required*
- Organisation Id *required*
- Output File *required*
- Hostname suffix *optional* (default: keboola.com)

- Run the command
    ```
    php ./cli.php manage:describe-organization-workspaces <manage-token> <organization-id> <output-file> <hostname-suffix> 
    ```

### Delete Sandboxes/Workspaces that were created by no longer active token id
This command can be used to delete all sandboxes and workspaces in a project that were created with a token that is no longer active in the project.
To also delete shared workspaces created by inactive tokens use the `--includeShared` option.
It will perform a dry run unleass the `--force/-f` option is applied.

Arguments:
- Storage Token *required*
- Hostname suffix *optional* (default: keboola.com)

Options:
- `--force/-f`
- `--includeShared`

- Run the command
    ```
    php ./cli.php storage:delete-ownerless-workspaces [--force/-f] [--includeShared] <storage-token> <hostname-suffix> 
    ```
### Delete all sandboxes in a project
Bulk delete all sandboxes in a project (and their underlying storage workspaces). Dry-run by default.

```
php cli.php storage:delete-project-sandboxes [--force/-f] [--includeShared] <storageToken> [<hostnameSuffix>]
```
Arguments:
- storageToken (required) Storage API token for the target project.
- hostnameSuffix (optional, default: keboola.com) Connection host suffix (e.g. eu-central-1.keboola.com).

Options:
- --force / -f     Actually perform deletions. Without it the command just lists what would be deleted.
- --includeShared  Include shared sandboxes; by default shared ones are skipped.

Behavior:
- Lists all sandboxes via Sandboxes API.
- (Unless --includeShared) skips those marked shared.
- For DB-type sandboxes deletes associated Storage workspace (physicalId or staging workspace) first, then deletes sandbox.
- Prints summary: X sandboxes deleted and Y storage workspaces deleted.

### Delete multiple project workspaces access projects
Delete specific Snowflake sandboxes and storage workspaces across multiple projects by workspace schema names.

```
php cli.php manage:mass-delete-project-workspaces [-f|--force] <stack-suffix> <source-file>
```
Arguments:
- stack-suffix (required) Stack host suffix (e.g. keboola.com, eu-central-1.keboola.com).
- source-file (required) CSV without header, two columns per line: <projectId>,<WORKSPACE_schema>. Example:
  ```
  12345,WORKSPACE_111111111
  98765,WORKSPACE_222222222
  ```

Options:
- --force / -f  Create and wait for delete jobs and actually delete matching storage workspaces. Without it the command only reports (dry-run).

Behavior:
- Builds a map of projectId => list of workspace schemas to delete; validates schema names start with WORKSPACE_.
- For each project it interactively prompts (STDIN) for that project's STORAGE token (one-by-one) so tokens aren't stored in file.
- Enumerates all dev branches, lists sandboxes per branch, matches schemas, and (force) queues delete jobs (via queue API) for sandboxes; waits until the jobs finish.
- Then enumerates Storage workspaces per branch and deletes any whose schema is still pending.
- Any schemas not found are printed for manual follow-up.
- Currently targeted at Snowflake (SNFLK) workspaces only.

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

### Purge deleted projects
Purge already deleted projects (remove residual metadata, optionally ignoring backend errors) using a Manage API token and a CSV piped via STDIN.

```
cat deleted-projects.csv | php cli.php storage:deleted-projects-purge [--ignore-backend-errors] <manageToken>
```
Input CSV header must be exactly:
```
id,name
```
Behavior:
- Validates header.
- For each row calls Manage API purgeDeletedProject; prints command execution id.
- Polls every second (max 600s) until project `isPurged` is true; errors on timeout.
- With --ignore-backend-errors it instructs API to ignore backend failures and just purge metadata (buckets/workspaces records).

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
This commaand is rather specific to BYODB snowflake backend migration.
You can use it to set all projects of an organization to use a storage backend.

- Run the command
    ```
    php ./cli.php manage:set-organization-storage-backend [--force/-f] <manage-token> <organization-id> <storage-backend-id> <hostname-suffix> 
    ```

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
