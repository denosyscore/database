<?php

declare(strict_types=1);

use Denosys\Database\Connection\Connection;
use Denosys\Database\Connection\ConnectionFactory;
use Denosys\Database\Grammar\MySqlGrammar;
use Denosys\Database\Grammar\PostgresGrammar;
use Denosys\Database\Query\Builder;
use Denosys\Database\Query\Sql;
use PHPUnit\Framework\TestCase;

final class RecordingConnection extends Connection
{
    /** @var list<string> */
    public array $statements = [];

    public function statement(string $query, array $bindings = []): bool
    {
        $this->statements[] = $query;

        return true;
    }
}

final class ImmutableQueryBuilderTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = (new ConnectionFactory())->make(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->connection->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, team_id INTEGER NULL, banned INTEGER NOT NULL DEFAULT 0)');
        $this->connection->statement('CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT, owner_id INTEGER NULL)');
        $this->connection->statement('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, title TEXT)');
        for ($i = 1; $i <= 10; $i++) {
            $this->connection->insert('INSERT INTO users (name, team_id, banned) VALUES (?, ?, ?)', ["user{$i}", $i <= 6 ? 1 : 2, $i % 5 === 0 ? 1 : 0]);
        }
        $this->connection->insert('INSERT INTO teams (id, name, owner_id) VALUES (1, ?, 1), (2, ?, 99)', ['red', 'blue']);
        $this->connection->insert('INSERT INTO posts (user_id, title) VALUES (1, ?), (1, ?), (3, ?)', ['a', 'b', 'c']);
    }

    public function testEveryBuilderCallReturnsANewQueryAndLeavesTheOriginalUnchanged(): void
    {
        $base = $this->connection->table('users');
        $sql = $base->toSql();

        $calls = [
            fn (Builder $q) => $q->where('id', '>', 1),
            fn (Builder $q) => $q->orWhere('id', '=', 1),
            fn (Builder $q) => $q->whereIn('id', [1, 2]),
            fn (Builder $q) => $q->whereNull('team_id'),
            fn (Builder $q) => $q->whereBetween('id', [1, 3]),
            fn (Builder $q) => $q->whereRaw('id > ?', [1]),
            fn (Builder $q) => $q->whereColumn('id', '=', 'team_id'),
            fn (Builder $q) => $q->select('id'),
            fn (Builder $q) => $q->addSelect('name'),
            fn (Builder $q) => $q->distinct(),
            fn (Builder $q) => $q->join('teams', 'teams.id', '=', 'users.team_id'),
            fn (Builder $q) => $q->orderBy('id'),
            fn (Builder $q) => $q->groupBy('team_id'),
            fn (Builder $q) => $q->having('team_id', '>', 0),
            fn (Builder $q) => $q->limit(1),
            fn (Builder $q) => $q->offset(1),
            fn (Builder $q) => $q->forPage(2, 3),
            fn (Builder $q) => $q->lockForUpdate(),
            fn (Builder $q) => $q->sharedLock(),
        ];

        foreach ($calls as $index => $call) {
            $changed = $call($base);
            self::assertNotSame($base, $changed, "call #{$index} returned the same object");
            self::assertSame($sql, $base->toSql(), "call #{$index} changed the original query");
            self::assertSame([], $base->getBindings(), "call #{$index} changed the original bindings");
        }
    }

    public function testABranchedQueryKeepsItsOwnConditions(): void
    {
        $active = $this->connection->table('users')->where('banned', '=', 0);
        $red = $active->where('team_id', '=', 1);
        $blue = $active->where('team_id', '=', 2);

        self::assertSame(8, $active->count());
        self::assertSame(5, $red->count());
        self::assertSame(3, $blue->count());
    }

    public function testNestedWhereUsesTheQueryTheCallbackReturns(): void
    {
        $query = $this->connection->table('users')
            ->where('banned', '=', 0)
            ->where(fn (Builder $q) => $q->where('id', '=', 1)->orWhere('id', '=', 10));

        self::assertSame('SELECT * FROM "users" WHERE "banned" = ? and ("id" = ? or "id" = ?)', $query->toSql());
        self::assertSame([0, 1, 10], $query->getBindings());
        self::assertSame(1, $query->count());
    }

    public function testANestedWhereCallbackThatReturnsNothingIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must return the query');

        $unused = $this->connection->table('users')->where(function (Builder $q): void {
            $discarded = $q->where('id', '=', 1); // the mistake: the condition is never returned
        });
    }

    public function testIdentifiersAreAlwaysQuotedAndRawSqlMustBeTyped(): void
    {
        $query = $this->connection->table('users');

        self::assertSame(
            'SELECT "id", lower(name) as lname FROM "users" ORDER BY lower(name) DESC',
            $query->select(['id', Sql::raw('lower(name) as lname')])->orderBy(Sql::raw('lower(name)'), 'desc')->toSql(),
        );

        foreach ([
            fn () => $query->orderBy('(select name from users limit 1)'),
            fn () => $query->select('count(*)'),
            fn () => $query->groupBy('lower(name)'),
            fn () => $query->having('count(id)', '>', 1),
            fn () => $query->where('lower(name)', '=', 'x'),
        ] as $index => $call) {
            try {
                $call();
                self::fail("call #{$index} accepted an expression as an identifier");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('Sql::raw()', $e->getMessage());
            }
        }
    }

    public function testOrderDirectionMustBeAscOrDesc(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $unused = $this->connection->table('users')->orderBy('id', 'desc; drop table users');
    }

    public function testHavingAcceptsATypedExpression(): void
    {
        $rows = $this->connection->table('users')
            ->select(['team_id', Sql::raw('count(*) as total')])
            ->groupBy('team_id')
            ->having(Sql::raw('count(*)'), '>', 5)
            ->get();

        self::assertCount(1, $rows);
        self::assertSame(1, (int) $rows[0]->team_id);
    }

    public function testLimitZeroReturnsNoRows(): void
    {
        self::assertSame([], $this->connection->table('users')->limit(0)->get());
    }

    public function testEmptyWhereInMatchesNothingAndEmptyWhereNotInMatchesEverything(): void
    {
        $users = $this->connection->table('users');

        self::assertSame(0, $users->whereIn('id', [])->count());
        self::assertSame(10, $users->whereNotIn('id', [])->count());
        self::assertSame(0, $users->where('id', '>', 0)->whereIn('id', [])->count());
    }

    public function testWhereInAcceptsASubqueryAndKeepsBindingsInOrder(): void
    {
        $owners = $this->connection->table('teams')->select('owner_id')->where('name', '=', 'red');

        $query = $this->connection->table('users')
            ->where('banned', '=', 0)
            ->whereIn('id', $owners)
            ->where('name', '!=', 'nobody');

        self::assertSame(
            'SELECT * FROM "users" WHERE "banned" = ? and "id" IN (SELECT "owner_id" FROM "teams" WHERE "name" = ?) and "name" != ?',
            $query->toSql(),
        );
        self::assertSame([0, 'red', 'nobody'], $query->getBindings());
        self::assertSame(['user1'], array_map(fn (object $row) => $row->name, $query->get()));
        self::assertSame(9, $this->connection->table('users')->whereNotIn('id', $owners)->count());
    }

    public function testWhereColumnComparesTwoColumns(): void
    {
        $query = $this->connection->table('users')->whereColumn('id', '=', 'team_id');

        self::assertSame('SELECT * FROM "users" WHERE "id" = "team_id"', $query->toSql());
        self::assertSame(1, $query->count()); // only user 1 is in team 1
    }

    public function testWhereExistsTakesACorrelatedSubquery(): void
    {
        $posts = $this->connection->table('posts')->select(Sql::raw('1'))->whereColumn('posts.user_id', '=', 'users.id');

        self::assertSame(2, $this->connection->table('users')->whereExists($posts)->count());
        self::assertSame(8, $this->connection->table('users')->whereNotExists($posts)->count());
    }

    public function testLocksCompileForEachDriver(): void
    {
        $mysql = new Builder(new Connection(new PDO('sqlite::memory:'), new MySqlGrammar()));
        $postgres = new Builder(new Connection(new PDO('sqlite::memory:'), new PostgresGrammar()));

        self::assertSame('SELECT * FROM `users` WHERE `id` = ? FOR UPDATE', $mysql->table('users')->where('id', '=', 1)->lockForUpdate()->toSql());
        self::assertSame('SELECT * FROM `users` LOCK IN SHARE MODE', $mysql->table('users')->sharedLock()->toSql());
        self::assertSame('SELECT * FROM "users" LIMIT 1 FOR UPDATE', $postgres->table('users')->limit(1)->lockForUpdate()->toSql());
        self::assertSame('SELECT * FROM "users" FOR SHARE', $postgres->table('users')->sharedLock()->toSql());
        // SQLite locks the whole database for writes; it has no row-lock syntax.
        self::assertSame('SELECT * FROM "users"', $this->connection->table('users')->lockForUpdate()->toSql());
    }

    public function testLazyByIdWalksEveryMatchingRowInChunks(): void
    {
        $this->connection->enableQueryLog();

        $names = [];
        foreach ($this->connection->table('users')->where('banned', '=', 0)->lazyById(3) as $row) {
            $names[] = $row->name;
        }

        self::assertSame(['user1', 'user2', 'user3', 'user4', 'user6', 'user7', 'user8', 'user9'], $names);
        self::assertCount(3, $this->connection->getQueryLog()); // 3 + 3 + 2 rows; a short chunk ends the walk
    }

    public function testLazyByIdRefusesAQueryThatAlreadyHasAnOrder(): void
    {
        $this->expectException(LogicException::class);

        iterator_to_array($this->connection->table('users')->orderBy('name')->lazyById(3));
    }

    public function testLazyByIdGroupsExistingConditionsBeforeTheCursor(): void
    {
        $ids = [];
        foreach ($this->connection->table('users')->where('id', '<=', 2)->orWhere('id', '=', 10)->lazyById(1) as $row) {
            $ids[] = (int) $row->id;
            self::assertLessThan(10, count($ids), 'lazyById() did not finish');
        }

        self::assertSame([1, 2, 10], $ids);
    }

    public function testLazyByIdRequiresTheKeyInTheSelection(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('[id]');

        iterator_to_array($this->connection->table('users')->select('name')->lazyById(3));
    }

    public function testValueAndPluckReadQualifiedColumns(): void
    {
        $users = $this->connection->table('users')->where('users.id', '<=', 2);

        self::assertSame('user1', $users->value('users.name'));
        self::assertSame([1 => 'user1', 2 => 'user2'], $users->pluck('users.name', 'users.id'));
        self::assertSame(['user1', 'user2'], $users->pluck('users.name as name'));
    }

    public function testCrossJoinIsRejectedBecauseItHasNoOnClause(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $unused = $this->connection->table('users')->join('teams', 'teams.id', '=', 'users.team_id', 'cross');
    }

    public function testMySqlUpsertUpdatesOnlyTheNamedColumns(): void
    {
        $connection = new RecordingConnection(new PDO('sqlite::memory:'), new MySqlGrammar());

        (new Builder($connection))->table('users')->upsert(['id' => 1, 'name' => 'a', 'role' => 'admin'], ['id'], ['name']);

        self::assertStringEndsWith('ON DUPLICATE KEY UPDATE `name` = VALUES(`name`)', $connection->statements[0]);
    }
}
