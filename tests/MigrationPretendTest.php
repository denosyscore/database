<?php

declare(strict_types=1);

namespace Tests;

use Denosys\Database\Connection\Connection;
use Denosys\Database\Grammar\SqliteGrammar;
use Denosys\Database\Migration\MigrationRepository;
use Denosys\Database\Migration\Migrator;
use Denosys\Database\Schema\Grammar\SqliteSchemaGrammar;
use Denosys\Database\Schema\SchemaBuilder;
use PDO;
use PHPUnit\Framework\TestCase;

final class RecordingMigrationRepository extends MigrationRepository
{
    public bool $exists = false;

    /** @var array<string, array{batch: int, checksum: string, executed_at: string}> */
    public array $records = [];

    public function repositoryExists(): bool
    {
        return $this->exists;
    }

    public function createRepository(): void
    {
        $this->exists = true;
    }

    public function getRan(): array
    {
        return $this->records;
    }

    public function getNextBatchNumber(): int
    {
        return 1;
    }

    public function getMigrationsForSteps(int $steps): array
    {
        return array_reverse(array_keys($this->records));
    }

    public function log(string $migration, string $checksum, int $batch): void
    {
        $this->records[$migration] = [
            'batch' => $batch,
            'checksum' => $checksum,
            'executed_at' => 'now',
        ];
    }

    public function delete(string $migration): void
    {
        unset($this->records[$migration]);
    }
}

final class MigrationPretendTest extends TestCase
{
    public function testPretendOnEmptyDatabaseDoesNotRecordOrSkipLaterRealRun(): void
    {
        [$migrator, $repository, $schema] = $this->fixture();

        $plan = $migrator->pretend()->run();

        self::assertSame(1, $plan->count());
        self::assertFalse($repository->exists);
        self::assertSame([], $repository->records);
        self::assertFalse($schema->hasTable('widgets'));
        self::assertCount(1, $migrator->getPretendLog());

        $applied = $migrator->pretend(false)->run();

        self::assertSame(1, $applied->count());
        self::assertTrue($repository->exists);
        self::assertCount(1, $repository->records);
        self::assertTrue($schema->hasTable('widgets'));
    }

    public function testPretendRollbackAndFreshLeaveExistingSchemaAndHistoryUntouched(): void
    {
        [$migrator, $repository, $schema] = $this->fixture();
        $migrator->run();
        $before = $repository->records;

        self::assertSame(0, $migrator->pretend()->run()->count());
        self::assertSame($before, $repository->records);

        $rollback = $migrator->pretend()->rollback();
        self::assertSame(1, $rollback->count());
        self::assertTrue($schema->hasTable('widgets'));
        self::assertSame($before, $repository->records);

        $reset = $migrator->reset();
        self::assertSame(1, $reset->count());
        self::assertTrue($schema->hasTable('widgets'));
        self::assertSame($before, $repository->records);

        $fresh = $migrator->fresh();
        self::assertSame(1, $fresh->count());
        self::assertTrue($schema->hasTable('widgets'));
        self::assertSame($before, $repository->records);
        self::assertSame('Would drop all tables', $migrator->getPretendLog()[0]);
    }

    /** @return array{Migrator, RecordingMigrationRepository, SchemaBuilder} */
    private function fixture(): array
    {
        $connection = new Connection(new PDO('sqlite::memory:'), new SqliteGrammar());
        $schema = new SchemaBuilder($connection, new SqliteSchemaGrammar());
        $repository = new RecordingMigrationRepository($connection, $schema);
        $migrator = new Migrator($connection, $schema, $repository, __DIR__ . '/fixtures/migrations');

        return [$migrator, $repository, $schema];
    }
}
