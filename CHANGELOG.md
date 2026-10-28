# Changelog

## Unreleased

- Keep migration pretend mode read-only across run, rollback, reset, and
  fresh. Previewed migrations remain pending for a later real run. This is a
  backward-compatible safety correction and patch release candidate.
- Correct the static types of model `$fillable` and `$hidden` metadata to
  string lists. This does not change runtime behavior and is intended for the
  next backward-compatible patch release under the current 0.x line.
