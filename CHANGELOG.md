# Changelog

## Unreleased (proposed 0.2.3)

- Add the referenced `DatabaseException` type so unsupported-driver and PDO
  connection-failure paths throw the intended exception.
- Record migration execution time without a driver-specific SQL function, so
  SQLite can log migrations successfully.
