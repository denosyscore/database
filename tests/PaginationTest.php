<?php

declare(strict_types=1);

use Denosys\Database\Connection\Connection;
use Denosys\Database\Connection\ConnectionManager;
use Denosys\Database\Pagination\Ordering;
use Denosys\Database\Pagination\Page;
use Denosys\Database\Pagination\Paginated;
use Denosys\Database\Record\Records;
use Denosys\Database\Record\RecordStore;
use PHPUnit\Framework\TestCase;
use RecordFixtures\Part;

require_once __DIR__ . '/Record/fixtures.php';

final class PaginationTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $manager = new ConnectionManager();
        $manager->addConnection('default', ['driver' => 'sqlite', 'database' => ':memory:']);
        $manager->setDefaultConnection('default');
        $this->db = $manager->connection();
        $this->db->statement('CREATE TABLE parts (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        foreach (['a', 'b', 'c', 'd', 'e'] as $i => $name) {
            $this->db->table('parts')->insert(['id' => $i + 1, 'name' => $name]);
        }
        Records::use(new RecordStore($manager));
    }

    protected function tearDown(): void
    {
        Records::reset();
    }

    public function testAPageOfRecords(): void
    {
        $page = Part::query()->paginate(Page::of(2, 2), Ordering::descending('id'));

        self::assertSame(['c', 'b'], array_map(static fn (Part $part): string => $part->name, $page->items));
        self::assertSame(5, $page->total);
        self::assertSame(2, $page->perPage);
        self::assertSame(2, $page->currentPage);
        self::assertSame(3, $page->lastPage);
        self::assertSame(3, $page->from);
        self::assertSame(4, $page->to);
        self::assertSame(1, $page->previousPage);
        self::assertSame(3, $page->nextPage);
    }

    public function testAnOutOfRangePageClampsToTheLast(): void
    {
        $page = Part::query()->paginate(Page::of(9, 2), Ordering::ascending('id'));

        self::assertSame(3, $page->currentPage);
        self::assertSame(['e'], array_map(static fn (Part $part): string => $part->name, $page->items));
        self::assertNull($page->nextPage);
    }

    public function testAnEmptyResultIsPageOneOfOne(): void
    {
        $page = Part::query()->where('id', '>', 9)->paginate(Page::of(3, 2));

        self::assertSame([], $page->items);
        self::assertSame([0, 1, 1, 0, 0], [$page->total, $page->currentPage, $page->lastPage, $page->from, $page->to]);
        self::assertNull($page->previousPage);
    }

    public function testRecordsPaginateAndMap(): void
    {
        $page = Part::query()->paginate(Page::of(1, 2), Ordering::ascending('name'));

        self::assertSame(['a', 'b'], $page->map(static fn (Part $part): string => $part->name)->items);
        self::assertSame(5, $page->map(static fn (Part $part): string => $part->name)->total);
    }

    public function testFromInputParsesLenientlyAndClamps(): void
    {
        self::assertSame([1, 25], [Page::fromInput(null, null)->number, Page::fromInput(null, null)->perPage]);
        self::assertSame([3, 100], [Page::fromInput('3', '500')->number, Page::fromInput('3', '500')->perPage]);
        self::assertSame([1, 1], [Page::fromInput('-2', '0')->number, Page::fromInput('-2', '0')->perPage]);
        self::assertSame(1, Page::fromInput('2x', 10)->number);
        self::assertSame(10, Page::fromInput(1, 10, default: 10, max: 50)->perPage);
    }

    public function testPageOfRefusesNonPositiveValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Page::of(0, 10);
    }

    public function testOrderingRefusesAnExpression(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Ordering::ascending('id; drop table parts');
    }

    public function testTheQueryBuilderPaginatesRows(): void
    {
        $page = $this->db->table('parts')->paginate(Page::of(2, 2), Ordering::descending('id'));

        self::assertSame(['c', 'b'], array_map(static fn (object $row): mixed => $row->name ?? null, $page->items));
        self::assertSame([5, 3, 3, 4], [$page->total, $page->lastPage, $page->from, $page->to]);
    }

    public function testPaginatedIsGeneric(): void
    {
        self::assertInstanceOf(Paginated::class, $this->db->table('parts')->paginate(Page::of(1, 1)));
    }

    public function testDistinctRowsAreCountedAsTheyArePaged(): void
    {
        $this->db->table('parts')->insert(['id' => 6, 'name' => 'a']);

        $page = $this->db->table('parts')->select(['name'])->distinct()->paginate(Page::of(1, 10), Ordering::ascending('name'));

        self::assertSame(5, $page->total);
        self::assertCount(5, $page->items);
    }

    public function testFromInputRefusesANonPositiveMaximum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Page::fromInput(1, 10, max: 0);
    }
}
