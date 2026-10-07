# Changelog

## Unreleased (proposed 0.3.0)

- Add model and relation pagination with hydrated items, consistent empty-set
  metadata, and clone-based counting that preserves the caller's query.

## Unreleased (proposed 0.2.3)

- Make repository pagination metadata consistent for empty result sets.
- Correct the static types of model `$fillable` and `$hidden` metadata to
  string lists. This does not change runtime behavior and is intended for the
  next backward-compatible patch release under the current 0.x line.
- Require the package that provides `Denosys\Support\Collection`, fixing
  hydrated model queries and eager-loaded relation collections in clean
  installations.
- Preserve numeric PDO attribute identifiers when combining default,
  caller-supplied, MySQL init-command, and SSL options.
- Resolve MySQL-specific PDO constants without deprecation notices on PHP 8.5
  while retaining PHP 8.2 compatibility.
- Add the referenced `DatabaseException` type so unsupported-driver and PDO
  connection-failure paths throw the intended exception.
- Record migration execution time without a driver-specific SQL function, so
  SQLite can log migrations successfully.
