# Changelog

## 0.21.1 – 2026-11-13

### Fixed

- Records fill their `created_at` and `updated_at` columns by column name, so
  `$created_at` properties are filled as well as `$createdAt`. Only a `DateTimeImmutable` or
  `DateTimeInterface` property without a cast is filled.

## 0.21.0 – 2026-11-13

Records replace models (findings F02, F29 and the pagination part of F08).

### Added

- `Record` and the `Persists` trait: a final class with typed public
  properties is a record, with `query()`, `find()`, `findOrFail()`, `save()`,
  `delete()`, `refresh()` and `exists()`. `#[Table]`, `#[Id]`, `#[Column]` and
  `#[CastWith]` name tables, keys and columns and convert custom types.
  Property types convert values: `int`, `float`, `string`, `bool`,
  `DateTimeImmutable` in UTC and backed enums.
- `save()` updates only changed columns and runs no query when nothing
  changed; `createdAt` and `updatedAt` properties are filled in. A `NULL`
  original no longer counts as a change.
- `RecordQuery`, a typed, immutable query of records with explicit methods.
- Relations (`HasMany`, `HasOne`, `BelongsTo`, `BelongsToMany`, built with
  `Relations::*()`) that are strict: `get()` returns what `with()` loaded,
  including an empty value for parents without related rows, and throws
  `RelationNotLoaded` otherwise; `query()` always queries. Eager loading
  supports constraints and nested relations, and pivot columns stay off the
  related records (`pivot()`).
- `Page`, `Ordering` and `Paginated`, with `paginate()` on records and on the
  query builder.
- `Records`, which holds the store records use (`use()`, `reset()`).

### Removed (breaking)

- `Model`, `ModelBuilder`, `Entity`, `Repository`, the `Database` facade, the
  model relations, model factories (`Factory`, `HasFactory`),
  `LengthAwarePaginator`, `MassAssignmentException`,
  `LazyLoadingViolationException` and `ModelNotFoundException` (use
  `RecordNotFound`). Model attribute magic is gone with them: reading a
  property never runs a method.
- The `illuminate/collections` and `doctrine/inflector` requirements.

### Changed (breaking)

- `Builder::get()` and `Connection::select()` return `list<object>`.

## 0.20.0 – 2026-11-13

### Added

- The `mariadb` driver name, using the MySQL grammars.
- `ConnectionFactory::assertSupportedVersion(string $driver, string $version)`.

### Changed (breaking)

- Connecting checks the server version and throws `DatabaseException` below
  the supported minimums: MySQL 8.4, MariaDB 10.11, PostgreSQL 15 and
  SQLite 3.35.
- An unknown driver throws `DatabaseException` instead of falling back to
  MySQL.
- Bindings: a `DateTimeInterface` is converted to UTC and written in the
  grammar's date format; a backed enum binds its value; a `Stringable` binds
  its string; a unit enum throws `InvalidArgumentException`.
- `insert()` and `insertGetId()` return integer keys as `int` on every
  driver. A PostgreSQL `RETURNING` key keeps its column type, so a text key
  such as `'00123'` is returned as written.
- The MariaDB version is read from the release before `-MariaDB`, so
  distribution builds such as `10.11.13-MariaDB-0ubuntu0.24.04.1` connect.

## 0.19.0 – 2026-11-13

### Changed (breaking)

- `SchemaBuilder::create()` and `table()` run in one transaction on SQLite
  and PostgreSQL, so a failed change leaves the table as it was. MySQL and
  MariaDB commit each DDL statement implicitly and are unchanged.
- SQLite changes a column, or adds or drops a foreign key, by rebuilding the
  table with SQLite's documented procedure (create, copy, drop, rename,
  recreate indexes, `PRAGMA foreign_key_check`). Rebuilding never fires
  `ON DELETE` actions on other tables, and keeps column collations,
  composite foreign keys and their names, index definitions (partial,
  collated and descending indexes), `STRICT` and `WITHOUT ROWID`, and the
  `AUTOINCREMENT` sequence. `dropForeign()` with a name that matches no
  constraint throws. A table that another table references
  cannot be rebuilt inside a transaction while foreign keys are enforced, and
  tables with triggers, `CHECK` constraints or generated columns are refused;
  both throw `DatabaseException` and change nothing.
- PostgreSQL changes columns in place (`ALTER COLUMN ... TYPE`,
  `SET`/`DROP NOT NULL`, `SET`/`DROP DEFAULT`). Shortening a string column
  fails on a value that is too long instead of truncating it, and an
  auto-incrementing column keeps its sequence.
- An alter drops indexes before columns, so a column and its index can be
  dropped together.
- PostgreSQL introspection (`hasTable()`, `getColumns()`, `getIndexes()` and
  others) reads the connection's `current_schema()`, and accepts a qualified
  `schema.table`. `dropAllTables()` drops only the current schema's tables,
  in one transaction. Every schema in the `schema` setting is quoted in
  `search_path`; the setting accepts a name or a list of names.
- Default index and foreign key names use the table name without its schema.

### Fixed

- `getColumnType()` works on MySQL and MariaDB.
- `insertGetId()`, `Model` and `Repository` inserts use `RETURNING` on
  PostgreSQL instead of `lastval()`, which failed on tables without a
  sequence and aborted the surrounding transaction. `Repository` asks for
  its own `$primaryKey`.
- `insert()` on PostgreSQL still reports the key a sequence generated, and
  reads it under a savepoint inside a transaction, so an insert that used
  no sequence reports `'0'` and leaves the transaction usable.

## 0.18.0 – 2026-11-13

### Changed (breaking)

- `run()`, `rollback()` and `fresh()` hold a per-database lock
  (`GET_LOCK` on MySQL and MariaDB, an advisory lock on PostgreSQL, `flock()`
  on a file beside a SQLite database) and read pending migrations only once
  it is held. Two concurrent runs no longer both execute a migration; the
  second waits up to the `lockTimeout` (60 seconds) and then throws
  `MigrationException`.
- A migration and its record in `schema_migrations` commit in one
  transaction, as do a rollback and the record's removal. On SQLite and
  PostgreSQL a failure leaves neither behind. MySQL and MariaDB still commit
  DDL implicitly.
- `run()` throws `MigrationException` when a migration that already ran has
  changed on disk. `acceptChanges()` (`migrate --accept-changes`) continues
  and records the new checksums.
- `MigrationInterface::dependsOn()` is removed; migrations run in filename
  order. It matched classes with `instanceof`, which could not name the
  anonymous classes migrations are, and had no cycle check.
- `Migrator::getPath()` is replaced by `getPaths()`. `status()` no longer
  creates the `schema_migrations` table.
- `migrate:rollback` and `migrate:fresh` report a held lock as an error.

### Added

- The `Migrator` accepts several paths, and `addPath()` adds one, so a package
  can ship its own migrations. A name that appears in two paths throws.
- `pretend()` records the SQL each migration would issue; `migrate --pretend`
  prints it. `Connection::pretend()` records writes instead of running them,
  while reads still run.
- `MigrationRepository::updateChecksum()`.

### Fixed

- `MigrationRepository` validates the rows it reads; `getChecksum()` returns
  null instead of a non-string value.

## 0.17.0 – 2026-11-12

### Changed (breaking)

- Queries are immutable: every method that adds to a query (`where()`,
  `orderBy()`, `limit()`, `join()`, `select()` and the rest, and
  `ModelBuilder::with()`) returns a new query and leaves the original
  unchanged. Code that relied on the old in-place changes, such as
  `$query->where(...);` on its own line, must use the result:
  `$query = $query->where(...);`. These methods carry `#[\NoDiscard]`, so
  PHP 8.5 warns and PHPStan reports `method.resultDiscarded` where a result is
  dropped.
- A nested `where()` callback must return the query it receives, for example
  `fn (Builder $q) => $q->where(...)->orWhere(...)`. A callback that returns
  nothing throws `LogicException` instead of adding an empty group.
- Every string passed as a column is quoted as an identifier. A string
  containing `(` is rejected with `InvalidArgumentException`; write
  expressions with `Sql::raw()`. `orderBy()` accepts only `asc` and `desc`.
  `join()` accepts only `inner`, `left`, `right` and `cross`.
- `lazyById()` and `pluck()` read rows straight from the connection, so on a
  model query they return rows and values, not models.
- `Builder::cloneWithout()` is removed.

### Added

- `Sql::raw()` for expressions in `select()`, `orderBy()`, `groupBy()` and
  `having()`.
- `whereIn()` and `whereNotIn()` accept a subquery; `whereColumn()`,
  `whereExists()`, `whereNotExists()`.
- `lockForUpdate()` and `sharedLock()`.
- `lazyById()` for walking large tables in key order.

### Fixed

- `whereIn($column, [])` matched every row or failed with a syntax error; it
  now matches none, and `whereNotIn($column, [])` matches every row.
- `limit(0)` was ignored and returned every row; it now returns none.
- `upsert()` on MySQL updated every inserted column instead of the
  `$updateColumns` given, because they were passed in the wrong position.
- `value('users.name')` and `pluck('users.name', 'users.id')` returned null:
  a qualified or aliased column is read by its name in the result row.
- `join(..., 'cross')` is rejected: the grammar compiles every join with an
  ON clause, which a cross join cannot have.

## 0.16.0 – 2026-11-12

- Adds the `migrate`, `migrate:rollback`, `migrate:status`, `migrate:fresh`
  and `db:seed` commands (`Denosys\Database\Console`), moved from
  `denosyscore/console`. Requires `denosyscore/console`,
  `denosyscore/container` and `denosyscore/contracts`.

## 0.15.0 – 2026-11-12

- Released with the framework's 0.15 line; no changes to this package.

## 0.14.0 – 2026-11-12

- Released with the framework's 0.14 line; no changes to this package.

## 0.13.0 – 2026-11-12

- Released with the framework's 0.13 line; no changes to this package.

## 0.12.0 – 2026-11-12

- Released with the framework's 0.12 line; no changes to this package.

## Unreleased

- Accept `illuminate/collections` 13, matching the framework.

## 0.11.1 – 2026-11-12

### Fixed

- Requirements on other DenoSys packages are `^0.11`. In 0.11.0 they were
  still `^0.10`, which for a 0.x version allows only 0.10 releases, so 0.11.0
  packages resolved their siblings to 0.10 or could not be installed. Use
  0.11.1 instead of 0.11.0.

## 0.11.0 – 2026-11-12

- **Breaking:** `Denosys\Database\DatabaseServiceProvider` moved to the framework as `Denosys\Framework\Providers\DatabaseServiceProvider`. Packages no longer ship service providers; the framework wires packages together.
- **Breaking:** `Denosys\Database\Migration\MigrationServiceProvider` moved to the framework as `Denosys\Framework\Providers\MigrationServiceProvider`. Packages no longer ship service providers; the framework wires packages together.
- **Breaking:** queries return `Illuminate\Support\Collection`; `denosyscore/support` is retired.
- Requires `illuminate/collections` and `doctrine/inflector`; suggests `fakerphp/faker`.

## 0.10.0 – 2026-11-12

### Changed

- Released from the [denosyscore/framework](https://github.com/denosyscore/framework)
  monorepo, where this package now lives under `packages/database`. All DenoSys
  packages share one version line, so the version moves from v0.5.0 to
  0.10.0. There are no API changes in this release.
- Requirements on other DenoSys packages are `^0.10`.

## 0.5.0 – 2026-11-11

### Breaking changes

- Reading a model property only loads a relation when a public, argument-free
  method of that name declares a `Relation` return type. Other methods are
  never called by property reads (previously `$model->delete` deleted the row).
- Unknown comparison operators in `where()`, `join()` and `having()` throw
  `InvalidArgumentException` instead of silently becoming `=`.
- `where($column, null)` and `where($column, '=', null)` compile to
  `IS NULL`; `!=` and `<>` with null compile to `IS NOT NULL`; other
  operators with null throw.
- `update()` and `delete()` with `limit()`, `offset()` or `orderBy()` throw
  `LogicException` on drivers that cannot limit writes. MySQL and MariaDB
  compile `ORDER BY` and `LIMIT`; `offset()` is rejected everywhere. Previously
  the limit was ignored and every matching row was changed.
- Default index names include the table: `idx_{table}_{columns}`,
  `uq_{table}_{columns}`. Index names are per schema in SQLite and
  PostgreSQL, so `index('email')` on two tables no longer collides.
  `dropIndex([...])` and `dropUnique([...])` compute the same names. Indexes
  created with earlier default names must be dropped by name.
- PostgreSQL `truncate()` no longer adds `CASCADE`, so it cannot empty the
  tables that reference the truncated one.

### Fixes

- `count()`, `max()`, `min()`, `sum()`, `avg()`, `first()`, `value()`,
  `pluck()`, `find()` and `exists()` no longer change the builder they are
  called on. Aggregates ignore `limit()`, `offset()` and `orderBy()` and count
  grouped queries over their groups.
- PostgreSQL migrations no longer emit `UNSIGNED`, which PostgreSQL rejects,
  so `id()`, `foreignId()` and the unsigned column helpers work there.
- PostgreSQL `id()` and other auto-incrementing primary columns now declare a
  primary key. `BIGSERIAL` alone created only a sequence, so tables had no
  primary key and foreign keys to them failed.

### Testing

- CI runs the database suite against MySQL 8.4 and 9, MariaDB 10.11 and 11.4,
  and PostgreSQL 15 and 17, in addition to SQLite. MySQL 8.0 is no longer
  tested.

## 0.4.1

- Declare SQLite primary keys for non-incrementing columns marked with
  `Column::primary()`, so primary-key upserts work on newly created tables.

## 0.4.0

- Add read-only index metadata inspection on `SchemaBuilder` for MySQL, SQLite,
  and PostgreSQL. Results include index names, ordered columns, uniqueness,
  and primary-key status so schema migrations can inspect existing indexes.

## 0.3.0

- Add model and relation pagination with hydrated items, consistent empty-set
  metadata, and clone-based counting that preserves the caller's query.

- Keep migration pretend mode read-only across run, rollback, reset, and
  fresh. Previewed migrations remain pending for a later real run. This is a
  backward-compatible safety correction and patch release candidate.
- Make repository pagination metadata consistent for empty result sets.
- Preserve numeric PDO attribute identifiers when combining default,
  caller-supplied, MySQL init-command, and SSL options.
- Resolve MySQL-specific PDO constants without deprecation notices on PHP 8.5
  while retaining PHP 8.2 compatibility.
- Add the referenced `DatabaseException` type so unsupported-driver and PDO
  connection-failure paths throw the intended exception.
- Correct SQLite column discovery so guarded schema changes recognize existing
  columns.
- Emit declared foreign keys when creating SQLite tables, preserving referential
  actions such as cascading deletes.
- Record migration execution time without a driver-specific SQL function, so
  SQLite can log migrations successfully.
- Require the package that provides `Illuminate\Support\Collection`, fixing
  hydrated model queries and eager-loaded relation collections in clean
  installations.
- Correct the static types of model `$fillable` and `$hidden` metadata to
  string lists. This does not change runtime behavior and is intended for the
  next backward-compatible patch release under the current 0.x line.
