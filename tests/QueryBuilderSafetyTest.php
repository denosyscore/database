<?php

declare(strict_types=1);

use Denosys\Database\Connection\Connection;
use Denosys\Database\Connection\ConnectionFactory;
use PHPUnit\Framework\TestCase;

final class QueryBuilderSafetyTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = (new ConnectionFactory())->make(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->connection->statement('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT, deleted_at TEXT NULL)');
        for ($i = 1; $i <= 40; $i++) {
            $this->connection->insert('INSERT INTO items (name) VALUES (?)', ["item{$i}"]);
        }
    }

    public function testCountIgnoresPagingAndLeavesTheQueryUnchanged(): void
    {
        $query = $this->connection->table('items')->where('id', '>', 0)->forPage(2, 15);
        $sqlBefore = $query->toSql();

        self::assertSame(40, $query->count());
        self::assertSame($sqlBefore, $query->toSql());
        self::assertCount(15, $query->get());
    }

    public function testAggregatesDoNotChangeTheSelectedColumns(): void
    {
        $query = $this->connection->table('items');
        $query->count();
        $query->max('id');

        $rows = $query->get();
        self::assertCount(40, $rows);
        self::assertObjectHasProperty('name', $rows[0]);
    }

    public function testFirstValueAndExistsDoNotLimitLaterQueries(): void
    {
        $query = $this->connection->table('items');
        $query->first();
        $query->value('name');
        $query->exists();

        self::assertCount(40, $query->get());
    }

    public function testDeleteRefusesLimitOnDriversWithoutDeleteLimit(): void
    {
        $this->expectException(LogicException::class);

        try {
            $this->connection->table('items')->where('id', '>', 35)->limit(1)->delete();
        } finally {
            self::assertCount(40, $this->connection->select('SELECT id FROM items'));
        }
    }

    public function testUpdateRefusesLimitOnDriversWithoutUpdateLimit(): void
    {
        $this->expectException(LogicException::class);

        $this->connection->table('items')->where('id', '>', 35)->limit(1)->update(['name' => 'x']);
    }

    public function testUnknownOperatorIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $unused = $this->connection->table('items')->where('id', 'in', [1, 2]);
    }

    public function testUnknownJoinAndHavingOperatorsAreRejected(): void
    {
        try {
            $unused = $this->connection->table('items')->join('other', 'items.id', '= other.id OR 1=1 --', 'other.id');
            self::fail('join accepted an unknown operator');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $unused = $this->connection->table('items')->groupBy('name')->having('id', '> 0 OR 1=1 --', 1);
    }

    public function testWhereEqualsNullMatchesNullValues(): void
    {
        self::assertSame(40, $this->connection->table('items')->where('deleted_at', null)->count());
        self::assertSame(40, $this->connection->table('items')->where('deleted_at', '=', null)->count());
        self::assertSame(0, $this->connection->table('items')->where('deleted_at', '!=', null)->count());
        self::assertSame(0, $this->connection->table('items')->where('deleted_at', '<>', null)->count());
    }

    public function testComparingNullWithAnOrderingOperatorIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $unused = $this->connection->table('items')->where('deleted_at', '>', null);
    }
}
