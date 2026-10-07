<?php

declare(strict_types=1);

use Denosys\Database\Connection\ConnectionManager;
use Denosys\Database\Repository;
use PHPUnit\Framework\TestCase;

final class RepositoryPaginationMetadataTest extends TestCase
{
    public function testEmptyResultsHaveConsistentPageBounds(): void
    {
        $manager = new ConnectionManager();
        $manager->addConnection('default', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $manager->connection()->statement('CREATE TABLE records (id INTEGER PRIMARY KEY)');
        $repository = new class ($manager) extends Repository {
            protected string $table = 'records';
        };

        $page = $repository->paginate(9, 2);

        self::assertSame([], $page['data']);
        self::assertSame(0, $page['pagination']['total']);
        self::assertSame(1, $page['pagination']['current_page']);
        self::assertSame(1, $page['pagination']['last_page']);
        self::assertSame(0, $page['pagination']['from']);
        self::assertSame(0, $page['pagination']['to']);
    }
}
