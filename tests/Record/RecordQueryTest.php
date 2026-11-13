<?php

declare(strict_types=1);

use Denosys\Database\Connection\Connection;
use Denosys\Database\Connection\ConnectionManager;
use Denosys\Database\Exceptions\RecordNotFound;
use Denosys\Database\Query\Builder;
use Denosys\Database\Record\RecordStore;
use PHPUnit\Framework\TestCase;
use RecordFixtures\Gadget;

require_once __DIR__ . '/fixtures.php';

final class RecordQueryTest extends TestCase
{
    private Connection $db;
    private RecordStore $store;

    protected function setUp(): void
    {
        $manager = new ConnectionManager();
        $manager->addConnection('default', ['driver' => 'sqlite', 'database' => ':memory:']);
        $manager->setDefaultConnection('default');
        $this->db = $manager->connection();
        $this->db->statement('CREATE TABLE gadgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $this->db->statement('CREATE TABLE parts (id INTEGER PRIMARY KEY, gadget_id INTEGER NOT NULL, name TEXT NOT NULL)');
        $this->db->statement("INSERT INTO parts (id, gadget_id, name) VALUES (10, 1, 'p')");
        $this->db->statement('CREATE TABLE authors (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $this->db->statement("INSERT INTO authors (id, name) VALUES (1, 'ann')");
        foreach (['a', 'b', 'c'] as $name) {
            $this->db->table('gadgets')->insert(['name' => $name]);
        }
        $this->store = new RecordStore($manager);
    }

    public function testGetReturnsAListOfHydratedRecords(): void
    {
        $gadgets = $this->store->query(Gadget::class)->orderByDesc('id')->get();

        self::assertSame(['c', 'b', 'a'], array_map(static fn (Gadget $g): string => $g->name, $gadgets));
        self::assertTrue(array_is_list($gadgets));
        self::assertTrue($this->store->isPersisted($gadgets[0]));
    }

    public function testModifiersLeaveTheOriginalUnchanged(): void
    {
        $all = $this->store->query(Gadget::class);
        $some = $all->where('name', '!=', 'a')->limit(1);

        self::assertSame(3, $all->count());
        self::assertCount(1, $some->get());
        self::assertSame(2, $all->whereIn('name', ['a', 'b'])->count());
        self::assertSame(0, $all->whereNull('name')->count());
        self::assertSame(3, $all->whereNotNull('name')->count());
    }

    public function testFirstFindAndExists(): void
    {
        $query = $this->store->query(Gadget::class);

        self::assertSame('b', $query->where('name', '=', 'b')->first()?->name);
        self::assertNull($query->where('name', '=', 'z')->first());
        self::assertSame('c', $query->findOrFail(3)->name);
        self::assertNull($query->find(9));
        self::assertTrue($query->where('name', '=', 'a')->exists());
        self::assertFalse($query->where('name', '=', 'z')->exists());
    }

    public function testFirstOrFailThrows(): void
    {
        $this->expectException(RecordNotFound::class);

        $this->store->query(Gadget::class)->where('name', '=', 'z')->firstOrFail();
    }

    public function testBulkUpdateAndDeleteDoNotTouchLoadedRecords(): void
    {
        $loaded = $this->store->findOrFail(Gadget::class, 1);
        $query = $this->store->query(Gadget::class);

        self::assertSame(1, $query->where('id', '=', 1)->update(['name' => 'z']));
        self::assertSame('a', $loaded->name);
        self::assertSame(2, $query->where('id', '>', 1)->delete());
        self::assertSame(1, $query->count());
    }

    public function testTapAndToBase(): void
    {
        $query = $this->store->query(Gadget::class)->tap(static fn (Builder $b): Builder => $b->where('name', '=', 'c'));

        self::assertSame(['c'], array_map(static fn (Gadget $g): string => $g->name, $query->get()));
        self::assertStringContainsString('"gadgets"', $query->toBase()->toSql());
    }

    public function testLazyByIdWalksEveryRecord(): void
    {
        $names = [];
        foreach ($this->store->query(Gadget::class)->lazyById(2) as $gadget) {
            $names[] = $gadget->name;
        }

        self::assertSame(['a', 'b', 'c'], $names);
    }

    public function testAJoinDoesNotOverwriteTheRecordsColumns(): void
    {
        $gadgets = $this->store->query(Gadget::class)
            ->tap(static fn (Builder $b): Builder => $b->join('parts', 'parts.gadget_id', '=', 'gadgets.id'))
            ->get();

        self::assertCount(1, $gadgets);
        self::assertSame(1, $gadgets[0]->id);
        self::assertSame('a', $gadgets[0]->name);
    }

    public function testTwoArgumentWhereMeansEquals(): void
    {
        self::assertSame(['b'], array_map(static fn (Gadget $g): string => $g->name, $this->store->query(Gadget::class)->where('name', 'b')->get()));
        self::assertSame(2, $this->store->query(Gadget::class)->where('name', 'a')->orWhere('name', 'c')->count());
    }

    public function testFindKeepsEarlierOrConditionsGrouped(): void
    {
        $query = $this->store->query(Gadget::class)->where('name', '=', 'a')->orWhere('name', '=', 'b');

        self::assertNull($query->find(3));
        self::assertSame('b', $query->find(2)?->name);
    }

    public function testEagerLoadingNeedsTheRecordsStore(): void
    {
        $other = new ConnectionManager();
        $other->addConnection('default', ['driver' => 'sqlite', 'database' => ':memory:']);
        $other->setDefaultConnection('default');
        \Denosys\Database\Record\Records::use(new RecordStore($other));

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessageMatches('/Records::store\(\)/');

            $this->store->query(\RecordFixtures\Author::class)->with(static fn (\RecordFixtures\Author $a) => $a->posts())->get();
        } finally {
            \Denosys\Database\Record\Records::reset();
        }
    }
}
