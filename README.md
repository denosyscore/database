# denosyscore/database

Database ORM, query builder, migrations, and seeding

## Status

Initial extraction snapshot from denosyscore monorepo as of 2026-02-14.

## Installation

composer require denosyscore/database

This installs `denosyscore/support`, which provides the
`Denosys\Support\Collection` returned by hydrated model queries and used by
eager-loaded relations. Database consumers do not need to define that class.

## Included Modules

- src/Database/*

## Model query pagination

`ModelBuilder::paginate(int $page = 1, int $perPage = 15)` returns a
`Denosys\Database\Pagination\LengthAwarePaginator` containing a hydrated
`Denosys\Support\Collection` in `items`. Its metadata includes `total`,
`perPage`, `currentPage`, `lastPage`, `from`, `to`, `previousPage`, and
`nextPage`. Relations forward `paginate()` to their model query, retaining
relation constraints. Apply a stable `orderBy()` before paginating when order
matters.

Pagination uses cloned count and page queries, leaving the caller's builder
unchanged. Out-of-range pages clamp to the last page. An empty result reports
page 1 of 1, `from` and `to` as 0, and an empty collection. Page and page size
must be positive; request-level upper bounds remain the caller's policy.

## Development

composer validate --strict
find src -type f -name '*.php' -print0 | xargs -0 -n1 php -l
composer test

## CI Workflows

- CI: Composer validation, PHP syntax lint, and model-query tests on supported
  PHP versions for push and pull requests.
- Release: GitHub release publication on semantic version tags.
- Dependabot: weekly Composer dependency update checks.
