# denosyscore/database

> **Read-only split.** This package is developed in [denosyscore/framework](https://github.com/denosyscore/framework) under `packages/database`. Open issues and pull requests there.

Records, a query builder, schema changes, migrations and seeding.

## Installation

```bash
composer require denosyscore/database
```

## Quick start

Connect, define a schema, then work with records or with the query builder. SQLite needs only the `pdo_sqlite` extension.

<!-- quickstart -->
```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Denosys\Database\Connection\ConnectionManager;
use Denosys\Database\Record\Attributes\Table;
use Denosys\Database\Record\Persists;
use Denosys\Database\Record\Record;
use Denosys\Database\Record\Records;
use Denosys\Database\Record\RecordStore;
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
});

// Query builder: rows, for set-based work
$db->table('books')->insert(['title' => 'Dune', 'year' => 1965]);
$db->table('books')->insert(['title' => 'Neuromancer', 'year' => 1984]);
echo $db->table('books')->where('year', '>', 1970)->value('title'), "\n";  // Neuromancer

// Records: one entity at a time
#[Table('books')]
final class Book implements Record
{
    use Persists;

    public ?int $id = null;
    public string $title;
    public int $year;
}

Records::use(new RecordStore($db));

$book = new Book();
$book->title = 'Kindred';
$book->year = 1979;
$book->save();

echo Book::findOrFail($book->id)->title, "\n";  // Kindred
echo Book::query()->count(), "\n";              // 3
```

## Query builder

Queries are immutable. Every method that adds to a query returns a new one
and leaves the original unchanged, so a query can be shared and branched:

```php
$active = $db->table('users')->where('banned', '=', 0);
$admins = $active->where('admin', '=', 1);      // $active is unchanged
$total  = $active->count();                      // never changes $active
```

Use the returned query: `$query->where(...);` on its own line does nothing.
PHP 8.5 warns about it at run time and PHPStan reports it
(`method.resultDiscarded`), because the builder's methods carry
`#[\NoDiscard]`. A nested group is a callback that returns the query it
receives:

```php
$users = $users->where(fn (Builder $q) => $q->where('role', '=', 'admin')->orWhere('owner', '=', 1));
```

Every string is quoted as an identifier, so a column name can never carry
SQL. Expressions are marked with `Sql::raw()`, which makes them visible in
review; never put user input in one, bind it instead:

```php
use Denosys\Database\Query\Sql;

$teams = $db->table('users')
    ->select(['team_id', Sql::raw('count(*) as total')])
    ->groupBy('team_id')
    ->having(Sql::raw('count(*)'), '>', 5)
    ->orderBy(Sql::raw('count(*)'), 'desc')
    ->get();
```

Also available:

- `whereIn($column, $subquery)` and `whereNotIn()` with a query instead of a
  list. An empty list matches no rows (`whereNotIn`: every row).
- `whereColumn('posts.user_id', '=', 'users.id')`, `whereExists($subquery)`
  and `whereNotExists()`.
- `lockForUpdate()` and `sharedLock()` inside a transaction. SQLite has no row
  locks (it serialises writers per database), so they add nothing there.
- `lazyById(500)` walks every matching row in key order, 500 rows per query,
  without loading them all.

## Records

A record is a final class that implements `Record` and uses the `Persists`
trait. Its public properties are its columns; nothing else is.

```php
use Denosys\Database\Record\Attributes\{CastWith, Column, Id, Table};

#[Table('users')]                 // default: snake-case plural of the class name
final class User implements Record
{
    use Persists;

    public ?int $id = null;        // the key: #[Id], or the property named $id
    public string $email;
    public string $firstName;      // column first_name
    #[Column('verified')]
    public bool $isVerified = false;
    public Status $status;         // a backed enum
    public ?DateTimeImmutable $createdAt = null;
    #[CastWith(MoneyCast::class)]  // a Cast converts types the store cannot
    public Money $balance;
}
```

- **Types convert values.** `int`, `float`, `string`, `bool`,
  `DateTimeImmutable` (read and written in UTC), backed enums, or a class
  implementing `Cast`. A `NULL` column read into a property that is not
  nullable throws `InvalidRecord`, as does a union or missing type. Keep
  decimals that must stay exact (money) as `string`, or cast them.
- **Saving writes what changed.** `save()` inserts a new record or updates
  only the changed columns of a loaded one; with no changes it runs no
  query. Properties left unset on insert take their column defaults, which
  are read back. `createdAt` and `updatedAt` properties are filled in.
- **Rollbacks do not reset records.** After a transaction rolls back, the
  records it saved still look saved; load them again before retrying.
- **No identity map.** Loading a row twice gives two objects. What the store
  knows about a record is released with the record, so workers do not grow.
- `find()`, `findOrFail()` (throws `RecordNotFound`), `refresh()`,
  `delete()` and `exists()` do what they say.
- `User::query()` returns a typed `RecordQuery`: `where()`, `whereIn()`,
  `orderBy()`, `limit()`, `lockForUpdate()` and the rest return a new query;
  `get()` returns `list<User>`, and `first()`, `count()`, `exists()`,
  `lazyById()` and `paginate()` work as on the builder. `update()` and
  `delete()` on a query change rows in bulk and leave loaded records alone.
  `tap(fn (Builder $b) => ...)` reaches any other builder method, and
  `toBase()` returns the builder.

Use records to load, change and save rows one entity at a time. Use the
query builder for reports, sums, bulk updates and read projections; it
returns rows, not records.

`Records::use()` sets the store records use; the framework does it during
bootstrap. Tests set their own, and long-running workers can call
`Records::reset()` between jobs.

### Relations

Relations are methods that return a relation object:

```php
use Denosys\Database\Record\Relations\{HasMany, Relations};

/** @return HasMany<self, Post> */
public function posts(): HasMany
{
    return Relations::hasMany($this, Post::class);   // posts.user_id = users.id
}
```

`hasMany()`, `hasOne()`, `belongsTo()` and `belongsToMany()` take the key
columns when they differ from the defaults (the snake-case class name plus
`_id`, and `id`).

Relations are strict. Load them with the query, one query per relation:

```php
$users = User::query()
    ->with(fn (User $u) => $u->posts()
        ->constrain(fn (RecordQuery $q) => $q->latest())
        ->with(fn (Post $p) => $p->comments()))
    ->get();

$users[0]->posts()->get();          // loaded: no query (an empty list when there are none)
User::findOrFail(9)->posts()->get(); // not loaded: throws RelationNotLoaded
User::findOrFail(9)->posts()->query()->get(); // runs a query, on purpose
```

An eager load runs one query for every parent, so `limit()` and `offset()`
in a `constrain()` are refused; use `query()` per record for that.

A `belongsToMany()` relation keeps pivot columns off the related records;
read them with `$post->tags()->pivot($tag)` on an eager-loaded tag.

### Pagination

```php
use Denosys\Database\Pagination\{Ordering, Page};

$page = User::query()->paginate(Page::fromInput($query['page'] ?? null, $query['per_page'] ?? null), Ordering::descending('id'));
$page->items;        // list<User>
$page->map(fn (User $u) => ['id' => $u->id, 'email' => $u->email]);
```

`Page::fromInput()` reads request values leniently (default 25 per page,
at most 100); `Page::of()` refuses values below 1. `Ordering` accepts column
names only. The query builder's `paginate()` returns rows. A page past the
end is clamped to the last page; no results is page 1 of 1, with `from` and
`to` both 0.

Model factories and `Records::fake()` are not part of this release; tests
use a SQLite `:memory:` database.

## Supported databases

The `driver` is `mysql`, `mariadb`, `pgsql` or `sqlite`; any other name is
refused. The server version is checked when the connection opens, and an
older release fails with a clear message instead of later, obscure SQL
errors:

| Database | Oldest supported |
|---|---|
| MySQL | 8.4 LTS |
| MariaDB | 10.11 LTS |
| PostgreSQL | 15 |
| SQLite | 3.35 |

`mariadb` uses the MySQL grammars; a MariaDB server also works under
`mysql`.

Bound values may be dates, enums or `Stringable` objects. A
`DateTimeInterface` is converted to UTC and written in the grammar's date
format, a backed enum binds its value, and a `Stringable` its string; an
enum without a backing value is refused. `insert()` and `insertGetId()`
return integer keys as integers on every driver; a PostgreSQL text key is
returned as written.

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

## Schema changes

`SchemaBuilder::create()` and `table()` behave the same on every driver,
within what each database can do:

- **All or nothing where the database allows it.** On SQLite and PostgreSQL,
  whose DDL is transactional, a `create()` or `table()` whose later statement
  fails leaves no half-made table. MySQL and MariaDB commit each DDL
  statement as it runs.
- **Changing columns.** `->change()` modifies a column in place: `MODIFY
  COLUMN` on MySQL, `ALTER COLUMN ... TYPE ... USING` plus nullability and
  default on PostgreSQL. SQLite cannot alter a column, so the table is
  rebuilt (below).
- **SQLite rebuilds.** Changing a column, or adding or dropping a foreign key,
  rebuilds the table the way SQLite documents: create the new table, copy
  the rows, drop the old one, rename, recreate indexes, check foreign keys.
  Rows, defaults, the primary key, indexes and foreign keys are kept. A
  table with triggers, CHECK constraints or generated columns is refused
  rather than rebuilt without them. Dropping a table that other tables
  reference would fire their ON DELETE actions, so enforcement is paused for
  the rebuild; SQLite can only do that outside a transaction, so inside one
  (a migration, by default) a referenced table is refused with instructions
  to return `false` from the migration's `withinTransaction()`.
- **Order of operations.** An alter drops foreign keys, then indexes, then
  columns, before adding, so `dropIndex(['email'])` with `dropColumn('email')`
  works everywhere.

### PostgreSQL schemas and qualified names

A connection's `schema` is one name or a list, set as the search path with
each name quoted:

```php
['driver' => 'pgsql', /* ... */, 'schema' => ['tenant_a', 'public']]
```

Introspection (`hasTable()`, `getColumns()`, `getTables()`, the migrations
table) looks in the connection's current schema, the first on the path. A
qualified name looks in another: `hasTable('reporting.events')`, and
`table('reporting.events')` in queries quotes each part. `dropAllTables()`
drops only the current schema's tables, in one statement and transaction.
On MySQL a qualified name is `database.table`.

## Migrations

The migrator runs migration files in filename order. Give it one directory or
several; a package adds its own with `addPath()`:

```php
$migrator = new Migrator($connection, $schema, new MigrationRepository($connection, $schema), [
    __DIR__ . '/database/migrations',
]);
$migrator->addPath(__DIR__ . '/vendor/acme/billing/migrations');
$migrator->run();
```

- **One run at a time.** `run()`, `rollback()` and `fresh()` hold a lock per
  database: `GET_LOCK` on MySQL and MariaDB, an advisory lock on PostgreSQL,
  and `flock()` on a file beside a SQLite database. A second run waits up to
  the lock timeout (60 seconds by default, the constructor's `lockTimeout`)
  and then throws `MigrationException`. Pending migrations are read once the
  lock is held, so two deploys never run the same migration.
- **A migration and its record commit together.** On SQLite and PostgreSQL,
  whose DDL is transactional, a failure leaves neither the change nor its
  record behind. MySQL and MariaDB commit DDL as it runs, so a migration that
  fails part-way can leave its earlier statements applied; keep each MySQL
  migration to one change.
- **A migration that already ran is never run again**, so editing one does not
  change the database. `run()` throws `MigrationException` when a recorded
  checksum no longer matches its file. Restore the file and add a new
  migration; if the edit does not change the schema (a comment, say),
  `acceptChanges()` (`migrate --accept-changes`) records the new checksums.
- **Preview.** `pretend()` runs nothing and records the SQL each migration
  would issue in `getPretendLog()`, each migration introduced by a
  `-- name` line. `migrate --pretend` prints it. Reads still run, so a
  migration that inspects the schema sees the real database. Turn pretend
  mode off before the real operation.

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
