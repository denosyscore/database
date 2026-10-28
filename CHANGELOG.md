# Changelog

## Unreleased

- Preserve numeric PDO attribute identifiers when combining default,
  caller-supplied, MySQL init-command, and SSL options.
- Resolve MySQL-specific PDO constants without deprecation notices on PHP 8.5
  while retaining PHP 8.2 compatibility.
- Correct the static types of model `$fillable` and `$hidden` metadata to
  string lists. This does not change runtime behavior and is intended for the
  next backward-compatible patch release under the current 0.x line.
