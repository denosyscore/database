# denosyscore/database

Database ORM, query builder, migrations, and seeding

## Status

Initial extraction snapshot from denosyscore monorepo as of 2026-02-14.

## Installation

composer require denosyscore/database

## Included Modules

- src/Database/*

SQLite table creation emits declared foreign keys, including configured
`ON DELETE` and `ON UPDATE` actions. Existing SQLite tables do not acquire
new constraints automatically; rebuild those tables in an explicit migration.

Connection setup failures and unsupported drivers raise
`Denosys\Database\Exceptions\DatabaseException`. When a PDO connection fails,
the original `PDOException` is available from `getPrevious()`.

## Development

composer validate --strict
find src -type f -name '*.php' -print0 | xargs -0 -n1 php -l
composer test

## CI Workflows

- CI: Composer validation, PHP syntax lint, and connection error tests on
  supported PHP versions for push and pull requests.
- Release: GitHub release publication on semantic version tags.
- Dependabot: weekly Composer dependency update checks.
