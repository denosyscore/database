# Changelog

## Unreleased (proposed 0.2.3)

- Add the referenced `DatabaseException` type so unsupported-driver and PDO
  connection-failure paths throw the intended exception.
- Emit declared foreign keys when creating SQLite tables, preserving referential
  actions such as cascading deletes.
