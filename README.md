# CLI UTILS

[![Build Status](https://travis-ci.org/keboola/cli-utils.svg?branch=master)](https://travis-ci.org/keboola/cli-utils)

Assorted CLI utils

### Running in Docker

`docker run --rm -it quay.io/keboola/cli-utils php ./cli.php`

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
php cli.php storage:projects-remove-feature MANAGETOKEN my-feature 1..100
```

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

`php cli.php manage:add-feature-to-templates [-f|--force] <token> <url> <featureName> [<featureDesc>]`


**This command supports dry-run. Add the `-f` flag if you want to submit the changes**

## Bulk operation on multiple stacks

If you want to run your command on multiple stacks, you can predefine stacks and `manageTokens` in `http-client` files and then use `manage:call-on-stacks` command to run it on all the defined stack. How?
1. make a copy of `http-client.env.json.dist` and `http-client.private.env.json.dist` and remove the `.dist` part.
2. Fill stack URLs and corresponding `manageToken`
3. Run the command in following form `php cli.php manage:call-on-stacks <target command> "<params of your commnand>"`
    - `<target command>` has to support arguments `token` and `url` in this order 
    - `"<params of your commnand>"` contain all the params for your target command but without `token` and `url` arguments. This part has to be quotet.
      - E.g. I want to run `manage:add-feature-to-templates <token> <url> featureXX featureDesc -f`
      - So I call `php cli.php manage:call-on-stacks manage:add-feature-to-templates "featureXX featureDesc -f"`
4. The command iterates over the stacks and asks your confirmation if you want to run the `taget command` there. You can skip it
