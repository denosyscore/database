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
    public function getRan(): array
    {
        $results = $this->connection->select(
            "SELECT migration, batch, checksum, executed_at FROM {$this->getTable()} ORDER BY batch, id"
        );

        $ran = [];
        foreach ($results as $row) {
            $row = (array) $row;
            if (!is_string($row['migration'] ?? null) || !is_numeric($row['batch'] ?? null)) {
                throw new \UnexpectedValueException('The migrations table holds a malformed row.');
            }
            $ran[$row['migration']] = [
                'batch' => (int) $row['batch'],
                'checksum' => is_string($row['checksum'] ?? null) ? $row['checksum'] : '',
                'executed_at' => is_scalar($row['executed_at'] ?? null) ? (string) $row['executed_at'] : '',
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
     * @return list<string>
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

        return $this->names($results);
    }

    /**
     * Get migrations for a specific number of batches.
     * 
     * @param int $steps Number of batches to roll back
     * @return list<string>
     */
    public function getMigrationsForSteps(int $steps): array
    {
        $lastBatch = $this->getLastBatchNumber();
        $minBatch = max(1, $lastBatch - $steps + 1);

        $results = $this->connection->select(
            "SELECT migration FROM {$this->getTable()} WHERE batch >= ? ORDER BY batch DESC, id DESC",
            [$minBatch]
        );

        return $this->names($results);
    }

    /**
     * Log that a migration has been run.
     */
    public function log(string $migration, string $checksum, int $batch): void
    {
        $this->connection->insert(
            "INSERT INTO {$this->getTable()} (migration, checksum, batch, executed_at) VALUES (?, ?, ?, ?)",
            [$migration, $checksum, $batch, gmdate('Y-m-d H:i:s')]
        );
    }

    /**
     * Record a new checksum for a migration that already ran.
     */
    public function updateChecksum(string $migration, string $checksum): void
    {
        $this->connection->update(
            "UPDATE {$this->getTable()} SET checksum = ? WHERE migration = ?",
            [$checksum, $migration]
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

        $checksum = $result !== null ? ((array) $result)['checksum'] ?? null : null;

        return is_string($checksum) ? $checksum : null;
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
     * @param array<object> $rows
     * @return list<string>
     */
    private function names(array $rows): array
    {
        $names = [];
        foreach ($rows as $row) {
            $name = ((array) $row)['migration'] ?? null;
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Get the table name.
     */
    private function getTable(): string
    {
        return self::TABLE;
    }
}
