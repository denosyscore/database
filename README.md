# denosyscore/database

Database ORM, query builder, migrations, and seeding

## Status

Initial extraction snapshot from denosyscore monorepo as of 2026-02-14.

## Installation

composer require denosyscore/database

This installs `denosyscore/support`, which provides the
`Denosys\Support\Collection` returned by hydrated model queries and used by
eager-loaded relations. Database consumers do not need to define that class.

## Included Modules

- src/Database/*

## Model attribute metadata

Model subclasses declare `$fillable` and `$hidden` as lists of attribute names,
not maps. The lists control mass assignment and serialization respectively.

```php
final class User extends \Denosys\Database\Model
{
    /** @var list<string> */
    protected array $fillable = ['name', 'email'];

    /** @var list<string> */
    protected array $hidden = ['password'];
}
```

## PDO connection options

Pass driver options under `options` in the connection configuration. Numeric
PDO attribute identifiers are preserved, so caller options override matching
defaults. For MySQL, the configured charset/collation init command and `ssl`
settings take precedence over conflicting entries in `options`.

## Model query pagination

`ModelBuilder::paginate(int $page = 1, int $perPage = 15)` returns a
`Denosys\Database\Pagination\LengthAwarePaginator` containing a hydrated
`Denosys\Support\Collection` in `items`. Its metadata includes `total`,
`perPage`, `currentPage`, `lastPage`, `from`, `to`, `previousPage`, and
`nextPage`. Relations forward `paginate()` to their model query, retaining
relation constraints. Apply a stable `orderBy()` before paginating when order
matters.

Pagination uses cloned count and page queries, leaving the caller's builder
unchanged. Out-of-range pages clamp to the last page. An empty result reports
page 1 of 1, `from` and `to` as 0, and an empty collection. Page and page size
must be positive; request-level upper bounds remain the caller's policy.

## Repository pagination

`Repository::paginate()` reports empty results as page 1 of 1, with `from`
and `to` both 0. Its existing response array shape is unchanged.

## Migration timestamps

Migration execution timestamps are stored in UTC using a bound value so the
migration repository works with SQLite, MySQL, and PostgreSQL.

## SQLite foreign keys

SQLite table creation emits declared foreign keys, including configured
`ON DELETE` and `ON UPDATE` actions. Existing SQLite tables do not acquire
new constraints automatically; rebuild those tables in an explicit migration.

## SQLite column discovery

On SQLite, `SchemaBuilder::getColumns()` and `hasColumn()` report existing
columns through the same public API used for other supported drivers.

## Connection errors

Connection setup failures and unsupported drivers raise
`Denosys\Database\Exceptions\DatabaseException`. When a PDO connection fails,
the original `PDOException` is available from `getPrevious()`.

## Development

composer validate --strict
find src -type f -name '*.php' -print0 | xargs -0 -n1 php -l
composer analyse
composer test

## CI Workflows

- CI: Composer validation, model metadata static analysis, PHP syntax lint,
  and database regression tests on supported PHP versions for push and pull
  requests.
- Release: GitHub release publication on semantic version tags.
- Dependabot: weekly Composer dependency update checks.
