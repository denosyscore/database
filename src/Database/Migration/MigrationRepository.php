<?php

declare(strict_types=1);

namespace Denosys\Database\Migration;

use Denosys\Database\Connection\Connection;
use Denosys\Database\Schema\SchemaBuilder;
use Denosys\Database\Schema\Blueprint;
use Denosys\Database\Schema\ColumnType;

/**
 * Repository for tracking migration execution.
 * 
 * Stores migration records in a database table with checksums
 * to detect if migrations have been modified after running.
 */
class MigrationRepository
{
    private const TABLE = 'schema_migrations';

    public function __construct(
        private readonly Connection $connection,
        private readonly SchemaBuilder $schema,
    ) {}

    /**
     * Create the migrations table if it doesn't exist.
     */
    public function createRepository(): void
    {
        if ($this->repositoryExists()) {
            return;
        }

        $this->schema->create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $table->column('migration', ColumnType::String)->length(255);
            $table->column('checksum', ColumnType::String)->length(64);
            $table->column('batch', ColumnType::Integer);
            $table->column('executed_at', ColumnType::Timestamp)
                  ->default('CURRENT_TIMESTAMP');
            
            $table->unique('migration', 'uq_migration');
        });
    }

    /**
     * Check if the migrations table exists.
     */
    public function repositoryExists(): bool
    {
        return $this->schema->hasTable(self::TABLE);
    }

    /**
     * Get all ran migrations.
     * 
     * @return array<string, array{batch: int, checksum: string, executed_at: string}>
     */
    /**
     * @return array<string, mixed>
     */
public function getRan(): array
    {
        $results = $this->connection->select(
            "SELECT migration, batch, checksum, executed_at FROM {$this->getTable()} ORDER BY batch, id"
        );

        $ran = [];
        foreach ($results as $row) {
            $row = (array) $row;
            $ran[$row['migration']] = [
                'batch' => (int) $row['batch'],
                'checksum' => $row['checksum'],
                'executed_at' => $row['executed_at'],
            ];
        }

        return $ran;
    }

    /**
     * Get the last batch number.
     */
    public function getLastBatchNumber(): int
    {
        $result = $this->connection->selectOne(
            "SELECT MAX(batch) as batch FROM {$this->getTable()}"
        );

        return $result ? (int) ((array) $result)['batch'] : 0;
    }

    /**
     * Get the next batch number.
     */
    public function getNextBatchNumber(): int
    {
        return $this->getLastBatchNumber() + 1;
    }

    /**
     * Get migrations from the last batch.
     * 
     * @return string[]
     */
    public function getLastBatch(): array
    {
        $lastBatch = $this->getLastBatchNumber();
        
        if ($lastBatch === 0) {
            return [];
        }

        $results = $this->connection->select(
            "SELECT migration FROM {$this->getTable()} WHERE batch = ? ORDER BY id DESC",
            [$lastBatch]
        );

        return array_map(fn($row) => ((array) $row)['migration'], $results);
    }

    /**
     * Get migrations for a specific number of batches.
     * 
     * @param int $steps Number of batches to roll back
     * @return string[]
     */
    public function getMigrationsForSteps(int $steps): array
    {
        $lastBatch = $this->getLastBatchNumber();
        $minBatch = max(1, $lastBatch - $steps + 1);

        $results = $this->connection->select(
            "SELECT migration FROM {$this->getTable()} WHERE batch >= ? ORDER BY batch DESC, id DESC",
            [$minBatch]
        );

        return array_map(fn($row) => ((array) $row)['migration'], $results);
    }

    /**
     * Log that a migration has been run.
     */
    public function log(string $migration, string $checksum, int $batch): void
    {
        $this->connection->insert(
            "INSERT INTO {$this->getTable()} (migration, checksum, batch, executed_at) VALUES (?, ?, ?, NOW())",
            [$migration, $checksum, $batch]
        );
    }

    /**
     * Remove a migration from the log.
     */
    public function delete(string $migration): void
    {
        $this->connection->delete(
            "DELETE FROM {$this->getTable()} WHERE migration = ?",
            [$migration]
        );
    }

    /**
     * Get the checksum for a migration.
     */
    public function getChecksum(string $migration): ?string
    {
        $result = $this->connection->selectOne(
            "SELECT checksum FROM {$this->getTable()} WHERE migration = ?",
            [$migration]
        );

        return $result ? ((array) $result)['checksum'] : null;
    }

    /**
     * Delete all migration records.
     */
    public function clear(): void
    {
        $this->connection->delete("DELETE FROM {$this->getTable()}");
    }

    /**
     * Drop the migrations table.
     */
    public function dropRepository(): void
    {
        $this->schema->dropIfExists(self::TABLE);
    }

    /**
     * Get the table name.
     */
    private function getTable(): string
    {
        return self::TABLE;
    }
}
