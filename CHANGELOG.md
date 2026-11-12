# Changelog

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
- Require the package that provides `Denosys\Support\Collection`, fixing
  hydrated model queries and eager-loaded relation collections in clean
  installations.
- Correct the static types of model `$fillable` and `$hidden` metadata to
  string lists. This does not change runtime behavior and is intended for the
  next backward-compatible patch release under the current 0.x line.
