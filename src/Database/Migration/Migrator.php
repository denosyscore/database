<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Migration;

use CFXP\Core\Database\Connection\Connection;
use CFXP\Core\Database\Schema\SchemaBuilder;
use Throwable;

/**
 * Orchestrates migration execution.
 * 
 * Runs migrations in order, tracks them in the repository,
 * and handles transactions and rollbacks.
 */
class Migrator
{
    /** @var array<string, MigrationInterface> */
    private array $migrations = [];

    private bool $pretend = false;

    /** @var array<int, string> */
    private array $pretendLog = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly SchemaBuilder $schema,
        private readonly MigrationRepository $repository,
        private readonly string $path,
    ) {}

    /**
     * Run all pending migrations.
     */
    public function run(): MigrationResult
    {
        $this->ensureRepositoryExists();
        
        $result = new MigrationResult();
        $pending = $this->getPendingMigrations();
        
        if (empty($pending)) {
            return $result;
        }

        $batch = $this->repository->getNextBatchNumber();

        foreach ($pending as $name => $migration) {
            $startTime = microtime(true);
            
            try {
                $this->runMigration($migration, 'up');
                $checksum = $this->getChecksum($name);
                $this->repository->log($name, $checksum, $batch);
                $result->addMigration($name, microtime(true) - $startTime);
            } catch (Throwable $e) {
                $result->addFailedMigration($name, microtime(true) - $startTime, $e->getMessage());
                break; // Stop on first error
            }
        }

        return $result;
    }

    /**
     * Rollback the last batch of migrations.
     */
    public function rollback(int $steps = 1): MigrationResult
    {
        $this->ensureRepositoryExists();
        
        $result = new MigrationResult();
        $migrations = $this->repository->getMigrationsForSteps($steps);
        
        if (empty($migrations)) {
            return $result;
        }

        foreach ($migrations as $name) {
            $startTime = microtime(true);
            
            try {
                $migration = $this->loadMigration($name);
                $this->runMigration($migration, 'down');
                $this->repository->delete($name);
                $result->addMigration($name, microtime(true) - $startTime);
            } catch (Throwable $e) {
                $result->addFailedMigration($name, microtime(true) - $startTime, $e->getMessage());
                break;
            }
        }

        return $result;
    }

    /**
     * Reset all migrations.
     */
    public function reset(): MigrationResult
    {
        $ran = $this->repository->getRan();
        $steps = count(array_unique(array_column($ran, 'batch')));
        return $this->rollback($steps);
    }

    /**
     * Drop all tables and re-run all migrations.
     */
    public function fresh(): MigrationResult
    {
        $this->schema->dropAllTables();
        return $this->run();
    }

    /**
     * Get migration status.
     * 
     * @return array<string, array{ran: bool, batch: int|null, checksum: string|null}>
     */
    public function status(): array
    {
        $this->ensureRepositoryExists();
        
        $all = $this->getMigrationFiles();
        $ran = $this->repository->getRan();
        $status = [];

        foreach ($all as $name => $path) {
            $status[$name] = [
                'ran' => isset($ran[$name]),
                'batch' => $ran[$name]['batch'] ?? null,
                'checksum' => $ran[$name]['checksum'] ?? null,
                'checksum_valid' => isset($ran[$name]) 
                    ? $ran[$name]['checksum'] === $this->getChecksum($name)
                    : null,
            ];
        }

        return $status;
    }

    /**
     * Get pending migrations.
     * 
     * @return array<string, MigrationInterface>
     */
    public function getPendingMigrations(): array
    {
        $all = $this->getMigrationFiles();
        $ran = array_keys($this->repository->getRan());
        
        $pending = [];
        foreach ($all as $name => $path) {
            if (!in_array($name, $ran, true)) {
                $pending[$name] = $this->loadMigration($name);
            }
        }

        return $this->sortByDependencies($pending);
    }

    /**
     * Enable pretend mode (dry run).
     */
    public function pretend(bool $pretend = true): static
    {
        $this->pretend = $pretend;
        return $this;
    }

    /**
     * Get the pretend log (SQL statements that would be executed).
     *
     * @return array<int, string>
     */
    public function getPretendLog(): array
    {
        return $this->pretendLog;
    }

    /**
     * Run a single migration.
     */
    private function runMigration(MigrationInterface $migration, string $method): void
    {
        if ($this->pretend) {
            // In pretend mode, we'd need to capture SQL statements
            // This would require query log integration
            $this->pretendLog[] = "Would {$method}: " . get_class($migration);
            return;
        }

        $useTransaction = $migration->withinTransaction() 
            && !$this->connection->inTransaction();

        if ($useTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $migration->$method($this->schema);
            
            // Only commit if we're still in a transaction
            // (MySQL DDL statements cause implicit commit)
            if ($useTransaction && $this->connection->inTransaction()) {
                $this->connection->commit();
            }
        } catch (Throwable $e) {
            // Only rollback if we're still in a transaction
            // (MySQL DDL statements cause implicit commit, ending the transaction)
            if ($useTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Load a migration by name.
     */
    private function loadMigration(string $name): MigrationInterface
    {
        if (isset($this->migrations[$name])) {
            return $this->migrations[$name];
        }

        $files = $this->getMigrationFiles();
        
        if (!isset($files[$name])) {
            throw new \RuntimeException("Migration not found: {$name}");
        }

        $migration = require $files[$name];
        
        if (!$migration instanceof MigrationInterface) {
            throw new \RuntimeException(
                "Migration must implement MigrationInterface: {$name}"
            );
        }

        $this->migrations[$name] = $migration;
        return $migration;
    }

    /**
     * Get all migration files.
     * 
     * @return array<string, string> name => path
     */
    private function getMigrationFiles(): array
    {
        if (!is_dir($this->path)) {
            return [];
        }

        $files = glob($this->path . '/*.php');
        $migrations = [];

        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $migrations[$name] = $file;
        }

        // Sort by name (which includes timestamp prefix)
        ksort($migrations);

        return $migrations;
    }

    /**
     * Sort migrations by their dependencies.
     *
     * @param array<string, MigrationInterface> $migrations
     * @return array<string, MigrationInterface>
     */
    private function sortByDependencies(array $migrations): array
    {
        $sorted = [];
        $resolved = [];
        
        $resolve = function (string $name, MigrationInterface $migration) use (&$resolve, &$sorted, &$resolved, $migrations): void {
            if (in_array($name, $resolved, true)) {
                return;
            }
            
            foreach ($migration->dependsOn() as $dependency) {
                // Find the migration file for this dependency class
                foreach ($migrations as $depName => $depMigration) {
                    if ($depMigration instanceof $dependency) {
                        $resolve($depName, $depMigration);
                    }
                }
            }
            
            $sorted[$name] = $migration;
            $resolved[] = $name;
        };

        foreach ($migrations as $name => $migration) {
            $resolve($name, $migration);
        }

        return $sorted;
    }

    /**
     * Get checksum for a migration file.
     */
    private function getChecksum(string $name): string
    {
        $files = $this->getMigrationFiles();
        
        if (!isset($files[$name])) {
            return '';
        }

        return hash_file('sha256', $files[$name]);
    }

    /**
     * Ensure the repository table exists.
     */
    private function ensureRepositoryExists(): void
    {
        $this->repository->createRepository();
    }

    /**
     * Get the migration path.
     */
    public function getPath(): string
    {
        return $this->path;
    }
}
