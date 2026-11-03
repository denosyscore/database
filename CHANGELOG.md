# Changelog

## Unreleased

- Make repository pagination metadata consistent for empty result sets.
- Require the package that provides `Denosys\Support\Collection`, fixing
  hydrated model queries and eager-loaded relation collections in clean
  installations.
- Correct the static types of model `$fillable` and `$hidden` metadata to
  string lists. This does not change runtime behavior and is intended for the
  next backward-compatible patch release under the current 0.x line.
