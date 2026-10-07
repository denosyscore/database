# Changelog

## Unreleased (proposed 0.2.3)

- Add the referenced `DatabaseException` type so unsupported-driver and PDO
  connection-failure paths throw the intended exception.
- Correct SQLite column discovery so guarded schema changes recognize existing
  columns.
