# AGENTS.md

Guidance for AI coding agents working in this repository.

## What this is

`keboola/cli-utils` is a Symfony Console application: a grab-bag of one-off/ad-hoc PHP CLI
commands used by Keboola engineers to perform bulk/manual operations against the Keboola
platform (Manage API, Storage API, Queue API, Scheduler API, Sandboxes API) — e.g. mass-adding
project features, deleting orphaned workspaces, migrating configurations, purging projects, etc.
There is no HTTP server, no persistent process, and no database — every command is invoked once
from the CLI and exits.

## Commands

All commands are run through `docker compose run --rm app php cli.php <command>` (or `dev` while
iterating locally). There is no native/host PHP toolchain assumed — Docker is the primary
workflow described in [README.md](README.md).

- Build the image: `docker compose build app` (or `dev` for the dev image with a bind mount)
- Install/update dependencies: `docker compose run --rm dev composer install`
- Run a CLI command: `docker compose run --rm app php cli.php <command> [args]`
- List all available commands: `docker compose run --rm app php cli.php list`
- Run the full test suite: `docker compose run --rm app composer tests` (plain `phpunit`, bootstrapped via `phpunit.xml.dist`)
- Run a single test file: `docker compose run --rm app ./vendor/bin/phpunit tests/DataAppOrchestratorTaskMigratorTest.php`
- Run a single test method: `docker compose run --rm app ./vendor/bin/phpunit --filter testMethodName tests/SomeTest.php`
- Static analysis (phpstan, level 9, `src/` only): `docker compose run --rm app composer phpstan`
- Code style check (PSR-2): `docker compose run --rm app ./vendor/bin/phpcs --standard=psr2 --ignore=vendor -n .`
- Auto-fix code style: `docker compose run --rm app ./vendor/bin/phpcbf --standard=psr2 -n .` (or `composer phpcbf` for `src/` only)

CI (`.github/workflows/build.yaml`) runs codestyle, phpstan, and tests on every push in exactly
that order, then tags/pushes the Docker image on tag builds. Keep changes passing all three
before considering a change done.

If working outside Docker (host PHP 8.3 + composer available), the same composer scripts and
`php cli.php` invocations work directly without the `docker compose run --rm app` prefix.

## Architecture

- **Single entrypoint, manual command registration**: [cli.php](cli.php) is the only entrypoint.
  It builds a Symfony `Application` and registers every command with `$application->add(new X())`
  by hand — commands are **not** auto-discovered. Adding a new command means creating the class
  under `src/Keboola/Console/Command/` *and* adding an `->add(new YourCommand())` line in `cli.php`.
- **PSR-0 autoloading, not PSR-4**: `composer.json` autoloads `Keboola\Console` via `psr-0` mapped
  to `src/`, so the namespace `Keboola\Console\Command\Foo` resolves to
  `src/Keboola/Console/Command/Foo.php` (the extra `Keboola/Console` path segments are part of the
  PSR-0 mapping, not a typo). Tests use PSR-4 (`Keboola\Console\Tests\` → `tests/`).
- **Every command is a flat `Symfony\Component\Console\Command\Command` subclass** in
  `src/Keboola/Console/Command/`, overriding `configure()` (name, args, options) and `execute()`.
  Command names are namespaced by which API they primarily hit: `manage:*` (Manage API,
  `Keboola\ManageApi\Client`), `storage:*` (Storage API, `Keboola\StorageApi\Client`), `queue:*`
  (Job Queue API). Most command logic — HTTP calls, pagination, printing — lives directly inside
  the command class; there's no service layer or DI container.
- **Dry-run by default is the dominant convention**: almost every mutating command takes a
  `-f`/`--force` flag and defaults to dry-run (reporting what *would* happen). Only pass `--force`
  in the actual command to make changes take effect. Preserve this convention for any new
  destructive command.
- **`token` then `url` as the first two arguments** is a de facto convention for commands meant to
  be run stack-wide, because `manage:call-on-stacks` (implemented in
  [AllStacksIterator.php](src/Keboola/Console/Command/AllStacksIterator.php)) builds a synthetic
  `StringInput` of the form `<command> <manageToken> <stackHost> <rest of params>` by reading
  per-stack tokens from `http-client.env.json`/`http-client.private.env.json` (git-ignored, copy
  from the `.dist` files) and re-invokes the target command through the same `Application`
  instance. A new stack-wide command should follow the `<token> <url> ...` argument order to stay
  compatible with this iterator.
- **Complex, testable logic is extracted into a plain (non-Command) class**: when a command's
  logic is intricate enough to warrant unit tests, the transformation logic lives in its own class
  (e.g. `DataAppOrchestratorTaskMigrator.php`, used by the
  `MigrateDataAppsOrchestratorTasks` command) so it can be tested against fakes without hitting the
  network. Most commands don't have this split and aren't unit tested — see
  `DataAppOrchestratorTaskMigratorTest.php` and `MigrateDataAppsOrchestratorTasksTest.php` for the
  pattern to follow when adding tests for a new command.
- **Tests fake the SDK client rather than mocking HTTP**: `tests/FakeComponents.php` is a minimal
  in-memory subclass of `Keboola\StorageApi\Components` overriding only the methods a given
  migrator needs (`listComponentConfigurations`, `getConfiguration`, `updateConfiguration`),
  recording calls in public arrays for assertions. Prefer this style of fake over mocking
  frameworks when the SDK client is easy to subclass.
- **`.env`** only carries `KBC_MANAGE_TOKEN_{US,EU,NE}` for `manage:mass-project-remove-expiration`;
  most commands take tokens/URLs as CLI arguments instead of environment configuration.

## Conventions to preserve

- New commands: prefer command names under the `manage:`/`storage:`/`queue:` prefixes matching the
  API they call, and default to dry-run behind `-f`/`--force` for anything destructive.
- Document new commands in [README.md](README.md) under the matching section ("Features",
  "Workspaces and sandboxes", "Project manipulation", "Jobs", "Utils"), following the existing
  format (usage line, Arguments/Options, Behavior).
- Keep `phpstan` at level 9 clean and PSR-2 style clean — both are enforced in CI.
