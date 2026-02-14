<?php

declare(strict_types=1);

namespace Denosys\Database\Migration;

/**
 * Value object representing the result of a migration operation.
 */
class MigrationResult
{
    /** @var MigrationRecord[] */
    /** @var array<string, mixed> */

    private array $migrations = [];
    
    private float $totalTime = 0.0;
    
    private bool $success = true;
    
    private ?string $error = null;

    /**
     * Add a successful migration record.
     */
    public function addMigration(string $name, float $time): void
    {
        $this->migrations[] = new MigrationRecord($name, $time, true);
        $this->totalTime += $time;
    }

    /**
     * Add a failed migration record.
     */
    public function addFailedMigration(string $name, float $time, string $error): void
    {
        $this->migrations[] = new MigrationRecord($name, $time, false, $error);
        $this->totalTime += $time;
        $this->success = false;
        $this->error = $error;
    }

    /**
     * Get all migration records.
     * 
     * @return MigrationRecord[]
     */
    /**
     * @return array<string, mixed>
     */
public function getMigrations(): array
    {
        return $this->migrations;
    }

    /**
     * Get total execution time.
     */
    public function getTotalTime(): float
    {
        return $this->totalTime;
    }

    /**
     * Check if all migrations succeeded.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Get the error message if failed.
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Get count of migrations run.
     */
    public function count(): int
    {
        return count($this->migrations);
    }

    /**
     * Check if any migrations were run.
     */
    public function isEmpty(): bool
    {
        return empty($this->migrations);
    }
}

/**
 * Record of a single migration execution.
 */
class MigrationRecord
{
    public function __construct(
        public readonly string $name,
        public readonly float $time,
        public readonly bool $success,
        public readonly ?string $error = null,
    ) {}
}
