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
php cli.php storage:projects-add-feature MANAGETOKEN my-feature 1..100
```

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
```

Prepare env:
```
export KBC_MANAGE_TOKEN_US=XXXXX
export KBC_MANAGE_TOKEN_EU=XXXXX
```

Run command:

`php cli.php manage:mass-project-remove-expiration extend.txt 0`

Use number of days or 0 as show to remove expiration completely. By default it's dry-run. Override with `-f` parameter.
