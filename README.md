# denosyscore/database

Database ORM, query builder, migrations, and seeding

## Status

Initial extraction snapshot from denosyscore monorepo as of 2026-02-14.

## Installation

composer require denosyscore/database

## Included Modules

- src/Database/*

## Model attribute metadata

Model subclasses declare `$fillable` and `$hidden` as lists of attribute names,
not maps. The lists control mass assignment and serialization respectively.

```php
final class User extends \Denosys\Database\Model
{
    /** @var list<string> */
    protected array $fillable = ['name', 'email'];

    /** @var list<string> */
    protected array $hidden = ['password'];
}
```

## Development

composer validate --strict
find src -type f -name '*.php' -print0 | xargs -0 -n1 php -l
composer analyse

## CI Workflows

- CI: Composer validation, model metadata static analysis, and PHP syntax lint on push and pull requests.
- Release: GitHub release publication on semantic version tags.
- Dependabot: weekly Composer dependency update checks.
