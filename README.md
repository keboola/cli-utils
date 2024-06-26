# CLI UTILS

[![Build](https://github.com/keboola/cli-utils/actions/workflows/build.yaml/badge.svg)](https://github.com/keboola/cli-utils/actions/workflows/build.yaml)

Assorted CLI utils

### Running in Docker

`docker run --rm -it keboola/cli-utils php ./cli.php`

## Mass Dedup

Loads project ids and tables from a CSV file and runs a dedup job on each table. 

**Command**

```
php cli.php storage:mass-dedup MANAGETOKEN /usr/ondra/data.csv
```

**Dry run**

Does not create the job or the snapshot.

```
php cli.php storage:mass-dedup MANAGETOKEN /usr/ondra/data.csv --dry-run
```

**CSV data sample**

```
data.csv
"project","table"
"232","out.c-rs-main.data"
"232","in.c-main.data"
```

## Redshift Deep Copy

Performs a deep copy of a table in Redshift backend. Fails if there are any aliases based on this table. 

```
php cli.php storage:redshift-deep-copy PROJECTTOKEN in.c-main.buggy-table
```

## Bulk Project Add Feature

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


## Bulk Project Remove Feature

Removes a project feature from multiple projects

```
php cli.php manage:projects-remove-feature [-f|--force] <token> <url> <featureName> <projects>
```
By in argument `<projects>` you can
- select projects to add the feature to by specifying project IDs  separated by a comma (e.g. `1,2,3,4`)
    -  `manage:projects-remove-feature <token> <url> <featureName> 1,2,3`
- OR run the script for ALL projects in the stack by passing `all` argument
    -  `manage:projects-remove-feature <token> <url> <featureName> all`

## Notify Projects

Prepare input `data.csv`:
```
"projectId","notificationTitle","notificationMessage"
"9","Test notification","Test notification content"
```

Command execution:
```
cat data.csv |  php cli.php storage:notify-projects MANAGETOKEN
```

## Mass project extend expiration:

Prepare input file "extend.txt" from looker https://keboola.looker.com/explore/keboola_internal_reporting/usage_metric_values: 
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

## Mass GD project drop:

Prepare input file "projects.csv" with project IDs:

```
123abc123abc123abc123abc123abc123abc
```

Prepare `.env` file from `.env.dist`:
```
GOODDATA_URL=
GOODDATA_LOGIN=
GOODDATA_PASSWORD=
```

Run command:

`php cli.php gooddata:delete-projects`

To actually drop the projects add `-f` flag. Default is dry-run. 

## Add a feature to project templates

You can add a project feature to all the project templates available on the stack

`php cli.php manage:add-feature-to-templates [-f|--force] <token> <url> <featureName> <featureTitle> [<featureDesc>]`


**This command supports dry-run. Add the `-f` flag if you want to submit the changes**

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

## Load Queue Jobs Lineage events into Marqueez

```
export STORAGE_API_TOKEN=<token>
php cli.php storage:lineage-events-export <marquezUrl> [<connectionUrl>] [--limit=100] [--job-names-configurations]
```

Loads last N _(default 100)_ jobs into Marquez tool. Export has two modes:
- default - jobs are identified by job IDs
- with `--job-names-configurations` option - job are identified by component and configuration IDs

## Mass Project Queue Migration

- Create a manage token.
- Prepare input file (e.g. "projects") with ids of the projects to migrate to new Queue.
    ```
    1234
    5678
    9012
    3456
    ```

- Run the mass migration command 
    ```
    php cli.php manage:mass-project-queue-migration <manage_token> <kbc_url> <file_with_projects>
    ```

   The command will do the following for every projectId in the source file:
   - add project feature `queuev2`
   - create and run configuration of `keboola.queue-migration-tool` component
   - if a job was successful, it will disable legacy orchestrations in the project
   - if a job ended with error, it will remove the `queuev2` feature from the project


- After migration delete your manage token you have created and used for migrations.


## Mass project enable dynamic backends
Prerequisities: https://keboola.atlassian.net/wiki/spaces/KB/pages/2135982081/Enable+Dynamic+Backends#Enable-for-project 

- Create a manage token.
- Prepare input file (e.g. "projects") with ID of the projects you want to enable dynamic backends for.
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

## Mass job termination command
This command can be used to terminate all jobs in a project in specified state (`created`, `waiting` or `processing`).

- Create a Storage token

- Run the command
    ```
    php ./cli.php queue:terminate-project-jobs <storage-token> <connection-url> <job-status>
    ```

## Delete Orphaned Workspaces command
This command can be used to delete all workspace in a project that were made for componentIds in the `component-list` argument
and that were created before the `until-date` argument.
The usecase for this command is to remove workspaces that were not cleaned after transformation failures.
It will perform a dry run unleass the `--force/-f` option is applied.

- Create a Storage token

- Run the command
    ```
    php ./cli.php storage:delete-orphaned=workspaces [--force/-f] <storage-token> <component-list> <untile-date> <hostname-suffix> 
    ```

## Delete Orphaned Workspaces in Organization command
This command can be used to delete all workspace in an organization that were made for componentIds in the `component` argument
and that were created before the `until-date` argument.
The usecase for this command is to remove workspaces that were not cleaned after transformation failures.
It will perform a dry run unleass the `--force/-f` option is applied.

- Create a Storage token

- Run the command
    ```
    php ./cli.php manage:delete-organization-workspaces [--force/-f] <manage-token> <organization-id> <component> <untile-date> <hostname-suffix> 
    ```

## Set Storage Backend for Orjanization
This commaand is rather specific to BYODB snowflake backend migration.
You can use it to set all projects of an organization to use a storage backend.

- Run the command
    ```
    php ./cli.php manage:set-organization-storage-backend [--force/-f] <manage-token> <organization-id> <storage-backend-id> <hostname-suffix> 
    ```

## Set maintenance mode for organization
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

## Delete Sandboxes/Workspaces that were created by no longer active token id
This command can be used to delete all sandboxes and workspaces in a project that were create with a token that is no longer active in the project
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

## Describe Connection Workspaces for an organization
This command takes an output file argument and writes out a csv describing all connection workspaces in an organisation.
The output file has header:
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

Arguments:
- Manage Token *required*
- Organisation Id *required*
- Output File *required*
- Hostname suffix *optional* (default: keboola.com)

- Run the command
    ```
    php ./cli.php manage:describe-organization-workspaces <manage-token> <organization-id> <output-file> <hostname-suffix> 
    ```

## License

MIT licensed, see [LICENSE](./LICENSE) file.
