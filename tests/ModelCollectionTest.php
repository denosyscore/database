<?php

declare(strict_types=1);

use Denosys\Database\Connection\ConnectionFactory;
use Denosys\Database\Model;
use Denosys\Database\ModelBuilder;
use Denosys\Support\Collection;
use PHPUnit\Framework\TestCase;

final class ModelCollectionTest extends TestCase
{
    public function testModelQueryReturnsTheDeclaredCollectionType(): void
    {
        $connection = (new ConnectionFactory())->make([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $connection->statement('CREATE TABLE records (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->statement("INSERT INTO records (id, name) VALUES (1, 'first')");

        $model = new class extends Model {
            protected string $table = 'records';
        };

        $rows = (new ModelBuilder($connection))->setModel($model)->table('records')->get();

        self::assertInstanceOf(Collection::class, $rows);
        self::assertCount(1, $rows);
        self::assertSame('first', $rows->first()?->name);
    }
}
