<?php

declare(strict_types=1);

use Denosys\Database\Connection\Connection;
use Denosys\Database\Connection\ConnectionFactory;
use Denosys\Database\Model;
use Denosys\Database\ModelBuilder;
use Denosys\Database\Pagination\LengthAwarePaginator;
use Denosys\Database\Relations\HasMany;
use Denosys\Support\Collection;
use PHPUnit\Framework\TestCase;

final class ModelPaginationTest extends TestCase
{
    public function testModelBuilderPaginatesHydratedItemsWithoutMutatingTheSource(): void
    {
        $connection = $this->connection();
        $model = new class extends Model {
            protected string $table = 'records';
        };
        $query = (new ModelBuilder($connection))->setModel($model)->table('records')->orderBy('id');

        self::assertTrue(method_exists($query, 'paginate'));

        $page = $query->paginate(2, 2);

        self::assertInstanceOf(LengthAwarePaginator::class, $page);
        self::assertInstanceOf(Collection::class, $page->items);
        self::assertSame(4, $page->total);
        self::assertSame(2, $page->currentPage);
        self::assertSame(2, $page->lastPage);
        self::assertSame(3, $page->from);
        self::assertSame(4, $page->to);
        self::assertSame([3, 4], array_map(
            static fn (Model $item): int => (int) $item->getAttribute('id'),
            $page->items->all(),
        ));
        self::assertCount(4, $query->get());
    }

    public function testRelationPaginationKeepsParentConstraintAndPageBounds(): void
    {
        $connection = $this->connection();
        $parent = new class extends Model {
            protected string $table = 'owners';
        };
        $parent->setAttribute('id', 1);
        $record = new class extends Model {
            protected string $table = 'records';
        };
        $relation = new HasMany(
            (new ModelBuilder($connection))->setModel($record)->table('records'),
            $parent,
            'owner_id',
            'id',
        );

        $page = $relation->orderBy('id')->paginate(2, 2);

        self::assertSame(3, $page->total);
        self::assertSame(2, $page->currentPage);
        self::assertSame(2, $page->lastPage);
        self::assertSame(3, $page->from);
        self::assertSame(3, $page->to);
        self::assertSame(1, $page->previousPage);
        self::assertNull($page->nextPage);
        self::assertSame(3, $page->items->first()->getAttribute('id'));
    }

    public function testEmptyAndOutOfRangePagesHaveConsistentMetadata(): void
    {
        $connection = $this->connection();
        $model = new class extends Model {
            protected string $table = 'records';
        };
        $query = (new ModelBuilder($connection))->setModel($model)->table('records');

        $empty = $query->where('owner_id', 99)->paginate(9, 2);

        self::assertSame(0, $empty->total);
        self::assertSame(1, $empty->currentPage);
        self::assertSame(1, $empty->lastPage);
        self::assertSame(0, $empty->from);
        self::assertSame(0, $empty->to);
        self::assertNull($empty->previousPage);
        self::assertNull($empty->nextPage);
        self::assertCount(0, $empty->items);

        $bounded = (new ModelBuilder($connection))->setModel($model)->table('records')
            ->orderBy('id')->paginate(PHP_INT_MAX, 2);

        self::assertSame(2, $bounded->currentPage);
        self::assertSame(3, $bounded->from);
        self::assertSame(4, $bounded->to);
    }

    public function testCountIgnoresSourceLimitButLeavesTheSourceUnchanged(): void
    {
        $connection = $this->connection();
        $model = new class extends Model {
            protected string $table = 'records';
        };
        $query = (new ModelBuilder($connection))->setModel($model)->table('records')->orderBy('id')->limit(1);

        $page = $query->paginate(2, 2);

        self::assertSame(4, $page->total);
        self::assertCount(2, $page->items);
        self::assertCount(1, $query->get());
    }

    public function testRejectsNonpositivePageAndPageSize(): void
    {
        $connection = $this->connection();
        $model = new class extends Model {
            protected string $table = 'records';
        };
        $query = (new ModelBuilder($connection))->setModel($model)->table('records');

        foreach ([[0, 2], [1, 0], [-1, 2], [1, -2]] as [$page, $perPage]) {
            try {
                $query->paginate($page, $perPage);
                self::fail('Nonpositive pagination input should throw.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Page and per-page values must be positive.', $exception->getMessage());
            }
        }
    }

    public function testPaginatorRejectsInvalidConstructionInputs(): void
    {
        foreach ([[0, 1], [1, 0]] as [$page, $perPage]) {
            try {
                new LengthAwarePaginator(new Collection(), 0, $page, $perPage);
                self::fail('Invalid pagination metadata should throw.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Page and per-page values must be positive.', $exception->getMessage());
            }
        }
    }

    private function connection(): Connection
    {
        $connection = (new ConnectionFactory())->make([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $connection->statement('CREATE TABLE records (id INTEGER PRIMARY KEY, owner_id INTEGER NOT NULL)');
        $connection->statement('INSERT INTO records (id, owner_id) VALUES (1, 1), (2, 1), (3, 1), (4, 2)');

        return $connection;
    }
}
