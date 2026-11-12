# denosyscore/database

> **Read-only split.** This package is developed in [denosyscore/framework](https://github.com/denosyscore/framework) under `packages/database`. Open issues and pull requests there.

Database ORM, query builder, migrations, and seeding

## Installation

```bash
composer require denosyscore/database
```

Hydrated model queries and eager-loaded relations return
`Illuminate\Support\Collection` from `illuminate/collections`, which this
package requires.

## Quick start

Connect, define a schema, then query with the builder or with Active Record models. SQLite needs only the `pdo_sqlite` extension.

<!-- quickstart -->
```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Denosys\Database\Connection\ConnectionManager;
use Denosys\Database\Model;
use Denosys\Database\Schema\Blueprint;
use Denosys\Database\Schema\Grammar\SqliteSchemaGrammar;
use Denosys\Database\Schema\SchemaBuilder;

$db = new ConnectionManager();
$db->addConnection('default', ['driver' => 'sqlite', 'database' => ':memory:']);
$db->setDefaultConnection('default');

$schema = new SchemaBuilder($db->connection(), new SqliteSchemaGrammar());
$schema->create('books', function (Blueprint $table): void {
    $table->id();
    $table->string('title');
    $table->integer('year');
    $table->timestamps();
});

// Query builder
$db->table('books')->insert(['title' => 'Dune', 'year' => 1965]);
$db->table('books')->insert(['title' => 'Neuromancer', 'year' => 1984]);
echo $db->table('books')->where('year', '>', 1970)->value('title'), "\n";  // Neuromancer

// Active Record
final class Book extends Model
{
    protected string $table = 'books';
    protected array $fillable = ['title', 'year'];
}

Model::setConnectionManager($db);
$book = Book::create(['title' => 'Kindred', 'year' => 1979]);
echo Book::findOrFail($book->id)->title, "\n";  // Kindred
echo Book::query()->count(), "\n";              // 3
```

## Included Modules

- src/Database/*

## Model query pagination

`ModelBuilder::paginate(int $page = 1, int $perPage = 15)` returns a
`Denosys\Database\Pagination\LengthAwarePaginator` containing a hydrated
`Illuminate\Support\Collection` in `items`. Its metadata includes `total`,
`perPage`, `currentPage`, `lastPage`, `from`, `to`, `previousPage`, and
`nextPage`. Relations forward `paginate()` to their model query, retaining
relation constraints. Apply a stable `orderBy()` before paginating when order
matters.

Pagination uses cloned count and page queries, leaving the caller's builder
unchanged. Out-of-range pages clamp to the last page. An empty result reports
page 1 of 1, `from` and `to` as 0, and an empty collection. Page and page size
must be positive; request-level upper bounds remain the caller's policy.

## PDO connection options

Pass driver options under `options` in the connection configuration. Numeric
PDO attribute identifiers are preserved, so caller options override matching
defaults. For MySQL, the configured charset/collation init command and `ssl`
settings take precedence over conflicting entries in `options`.

On SQLite, `SchemaBuilder::getColumns()` and `hasColumn()` report existing
columns through the same public API used for other supported drivers.

`SchemaBuilder::getIndexes($table)` returns indexes in name order for MySQL,
SQLite, and PostgreSQL. Each entry has `name`, `columns` (in key order),
`unique`, and `primary` fields. Expression index positions have a `null`
column name. A missing table returns an empty list. Inspect existing indexes
before replacing one, and check for duplicate values before adding a unique
constraint.

SQLite table creation emits declared foreign keys, including configured
`ON DELETE` and `ON UPDATE` actions. Existing SQLite tables do not acquire
new constraints automatically; rebuild those tables in an explicit migration.

Migration execution timestamps are stored in UTC using a bound value so the
migration repository works with SQLite, MySQL, and PostgreSQL.

Connection setup failures and unsupported drivers raise
`Denosys\Database\Exceptions\DatabaseException`. When a PDO connection fails,
the original `PDOException` is available from `getPrevious()`.

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

## Migration preview

`Migrator::pretend()->run()` lists pending migrations without creating the
migration repository, applying schema changes, or recording history. The
`MigrationResult` contains planned migration names, and `getPretendLog()`
contains descriptive `Would up` entries; it does not contain compiled SQL.
Pretend mode also keeps rollback, reset, and fresh operations read-only. Turn
pretend mode off before the real operation.

## Repository pagination

`Repository::paginate()` reports empty results as page 1 of 1, with `from`
and `to` both 0. Its existing response array shape is unchanged.

## Development

composer validate --strict
find src -type f -name '*.php' -print0 | xargs -0 -n1 php -l
composer test
composer analyse


## Releases

After CI passes on `main`, create an annotated semantic version tag from that
reviewed commit and push the tag. The Release workflow publishes a GitHub
Release. Confirm the matching version is available in the Composer package
index before updating dependent package constraints.
