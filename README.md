# denosyscore/database

Database ORM, query builder, migrations, and seeding

## Status

Initial extraction snapshot from denosyscore monorepo as of 2026-02-14.

## Installation

composer require denosyscore/database

## Included Modules

- src/Database/*

Migration execution timestamps are stored in UTC using a bound value so the
migration repository works with SQLite, MySQL, and PostgreSQL.

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
