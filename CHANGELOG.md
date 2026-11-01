# Changelog

## Unreleased

- Add the referenced `DatabaseException` type so unsupported-driver and PDO
  connection-failure paths throw the intended exception.
- Emit declared foreign keys when creating SQLite tables, preserving referential
  actions such as cascading deletes.
- Correct the static types of model `$fillable` and `$hidden` metadata to
  string lists. This does not change runtime behavior and is intended for the
  next backward-compatible patch release under the current 0.x line.
