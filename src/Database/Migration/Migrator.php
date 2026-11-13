<?php

declare(strict_types=1);

namespace Denosys\Database\Migration;

use Denosys\Database\Connection\Connection;
use Denosys\Database\Schema\SchemaBuilder;
use Throwable;

/**
 * Runs migrations in filename order and records them in the repository.
 *
 * - One run at a time: run(), rollback() and fresh() hold a per-database
 *   lock (see MigrationLock) and read the pending migrations only once the
 *   lock is held, so two deploys cannot run the same migration.
 * - A migration and its log row commit together. On SQLite and PostgreSQL,
 *   whose DDL is transactional, a failure leaves neither behind. MySQL and
 *   MariaDB commit DDL implicitly, so there a failure part-way through a
 *   migration can leave the statements before it applied.
 * - run() refuses to start when a migration that already ran has changed,
 *   unless acceptChanges() is set, which records the new checksums.
 * - pretend() runs nothing and records the SQL each migration would issue.
 */
class Migrator
{
    /** @var array<string, array{checksum: string, migration: MigrationInterface}> */
    private array $migrations = [];

    /** @var list<string> */
    private array $paths;

    private bool $pretend = false;

    private bool $acceptChanges = false;

    /** @var list<string> */
    private array $pretendLog = [];

    /**
     * @param string|array<string> $paths Directories of migration files.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly SchemaBuilder $schema,
        private readonly MigrationRepository $repository,
        string|array $paths,
        private readonly float $lockTimeout = 60.0,
    ) {
        $this->paths = is_string($paths) ? [$paths] : array_values($paths);
    }

    /**
     * Add a directory of migrations, for example a package's own.
     */
    public function addPath(string $path): static
    {
        if (!in_array($path, $this->paths, true)) {
            $this->paths[] = $path;
        }

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getPaths(): array
    {
        return $this->paths;
    }

    /**
     * Preview instead of running: record the SQL and change nothing.
     */
    public function pretend(bool $pretend = true): static
    {
        $this->pretend = $pretend;

        return $this;
    }

    /**
     * Let run() continue when migrations that already ran have changed, and
     * record their new checksums.
     */
    public function acceptChanges(bool $accept = true): static
    {
        $this->acceptChanges = $accept;

        return $this;
    }

    /**
     * The SQL recorded by the last pretend run, each migration introduced by
     * a "-- name" line.
     *
     * @return list<string>
     */
    public function getPretendLog(): array
    {
        return $this->pretendLog;
    }

    public function run(): MigrationResult
    {
        $this->pretendLog = [];

        if ($this->pretend) {
            return $this->apply($this->getPendingMigrations(), 0);
        }

        return $this->lock()->hold(function (): MigrationResult {
            $this->repository->createRepository();
            $this->guardChecksums();

            return $this->apply($this->getPendingMigrations(), $this->repository->getNextBatchNumber());
        });
    }

    public function rollback(int $steps = 1): MigrationResult
    {
        $this->pretendLog = [];

        if ($this->pretend) {
            return $this->repository->repositoryExists()
                ? $this->revert($this->repository->getMigrationsForSteps($steps))
                : new MigrationResult();
        }

        return $this->lock()->hold(function () use ($steps): MigrationResult {
            $this->repository->createRepository();

            return $this->revert($this->repository->getMigrationsForSteps($steps));
        });
    }

    public function reset(): MigrationResult
    {
        $this->pretendLog = [];

        if ($this->pretend) {
            return $this->repository->repositoryExists()
                ? $this->revert($this->repository->getMigrationsForSteps($this->repository->getLastBatchNumber()))
                : new MigrationResult();
        }

        // The batches are read under the lock, so a batch another process adds
        // while this one waits is reverted too. Every batch up to the last is
        // included, so a gap in the numbering leaves nothing behind.
        return $this->lock()->hold(function (): MigrationResult {
            $this->repository->createRepository();

            return $this->revert($this->repository->getMigrationsForSteps($this->repository->getLastBatchNumber()));
        });
    }

    /**
     * Drop every table in the connection's database, then run all migrations.
     */
    public function fresh(): MigrationResult
    {
        if ($this->pretend) {
            $this->pretendLog = ['-- drop all tables', ...$this->connection->pretend(fn () => $this->schema->dropAllTables())];
            $result = new MigrationResult();
            foreach ($this->getAllMigrations() as $name => $migration) {
                if (!$this->preview($result, $name, fn () => $migration->up($this->schema))) {
                    break;
                }
            }

            return $result;
        }

        return $this->lock()->hold(function (): MigrationResult {
            $this->schema->dropAllTables();
            $this->repository->createRepository();

            return $this->apply($this->getPendingMigrations(), $this->repository->getNextBatchNumber());
        });
    }

    /**
     * @return array<string, array{ran: bool, batch: int|null, checksum: string|null, checksum_valid: bool|null}>
     */
    public function status(): array
    {
        $ran = $this->repository->repositoryExists() ? $this->repository->getRan() : [];
        $status = [];

        foreach ($this->getMigrationFiles() as $name => $path) {
            $record = $ran[$name] ?? null;
            $status[$name] = [
                'ran' => $record !== null,
                'batch' => $record['batch'] ?? null,
                'checksum' => $record['checksum'] ?? null,
                'checksum_valid' => $record !== null ? $record['checksum'] === $this->getChecksum($path) : null,
            ];
        }

        return $status;
    }

    /**
     * @return array<string, MigrationInterface>
     */
    public function getPendingMigrations(): array
    {
        $ran = $this->repository->repositoryExists() ? $this->repository->getRan() : [];
        $pending = [];
        foreach ($this->getMigrationFiles() as $name => $path) {
            if (!isset($ran[$name])) {
                $pending[$name] = $this->loadMigration($name, $path);
            }
        }

        return $pending;
    }

    /**
     * @param array<string, MigrationInterface> $migrations
     */
    private function apply(array $migrations, int $batch): MigrationResult
    {
        $result = new MigrationResult();
        $files = $this->getMigrationFiles();

        foreach ($migrations as $name => $migration) {
            $start = microtime(true);

            if ($this->pretend) {
                if (!$this->preview($result, $name, fn () => $migration->up($this->schema))) {
                    break;
                }
                continue;
            }

            try {
                $this->transactionally($migration, function () use ($migration, $name, $files, $batch): void {
                    $migration->up($this->schema);
                    $this->repository->log($name, $this->getChecksum($files[$name]), $batch);
                });
                $result->addMigration($name, microtime(true) - $start);
            } catch (Throwable $e) {
                $result->addFailedMigration($name, microtime(true) - $start, $e->getMessage());
                break;
            }
        }

        return $result;
    }

    /**
     * @param list<string> $names
     */
    private function revert(array $names): MigrationResult
    {
        $result = new MigrationResult();
        $files = $this->getMigrationFiles();

        foreach ($names as $name) {
            $start = microtime(true);

            try {
                if (!isset($files[$name])) {
                    throw new \RuntimeException("Migration not found: {$name}");
                }
                $migration = $this->loadMigration($name, $files[$name]);

                if ($this->pretend) {
                    if (!$this->preview($result, $name, fn () => $migration->down($this->schema))) {
                        break;
                    }
                    continue;
                }

                $this->transactionally($migration, function () use ($migration, $name): void {
                    $migration->down($this->schema);
                    $this->repository->delete($name);
                });
                $result->addMigration($name, microtime(true) - $start);
            } catch (Throwable $e) {
                $result->addFailedMigration($name, microtime(true) - $start, $e->getMessage());
                break;
            }
        }

        return $result;
    }

    /**
     * Record the SQL $work would issue. A migration that reads the schema
     * may depend on earlier pending migrations, which a preview does not
     * apply; the preview stops there and says so.
     *
     * @param callable(): void $work
     */
    private function preview(MigrationResult $result, string $name, callable $work): bool
    {
        try {
            $this->pretendLog = [...$this->pretendLog, "-- {$name}", ...$this->connection->pretend(static fn () => $work())];
            $result->addMigration($name, 0.0);

            return true;
        } catch (Throwable $e) {
            $result->addFailedMigration($name, 0.0, 'Preview stopped: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @param callable(): void $work
     */
    private function transactionally(MigrationInterface $migration, callable $work): void
    {
        if ($migration->withinTransaction()) {
            $this->connection->transaction(static fn () => $work());
        } else {
            $work();
        }
    }

    /**
     * Refuse to run when a migration that already ran has changed on disk.
     */
    private function guardChecksums(): void
    {
        $files = $this->getMigrationFiles();
        $changed = [];

        foreach ($this->repository->getRan() as $name => $record) {
            if (!isset($files[$name])) {
                continue;
            }
            $checksum = $this->getChecksum($files[$name]);
            if ($record['checksum'] === $checksum) {
                continue;
            }
            if ($this->acceptChanges) {
                $this->repository->updateChecksum($name, $checksum);
            } else {
                $changed[] = $name;
            }
        }

        if ($changed !== []) {
            throw MigrationException::changed($changed);
        }
    }

    /**
     * @return array<string, MigrationInterface>
     */
    private function getAllMigrations(): array
    {
        $migrations = [];
        foreach ($this->getMigrationFiles() as $name => $path) {
            $migrations[$name] = $this->loadMigration($name, $path);
        }

        return $migrations;
    }

    private function loadMigration(string $name, string $path): MigrationInterface
    {
        // Reload a file that changed since it was loaded, so the code that runs
        // is the code whose checksum is recorded.
        $checksum = $this->getChecksum($path);
        if (($this->migrations[$name]['checksum'] ?? null) === $checksum) {
            return $this->migrations[$name]['migration'];
        }

        $migration = require $path;
        if (!$migration instanceof MigrationInterface) {
            throw new \RuntimeException("Migration [{$name}] must return an instance of " . MigrationInterface::class . '.');
        }

        $this->migrations[$name] = ['checksum' => $checksum, 'migration' => $migration];

        return $migration;
    }

    /**
     * Every migration file across the paths, ordered by filename.
     *
     * @return array<string, string> name => path
     */
    private function getMigrationFiles(): array
    {
        $migrations = [];
        foreach ($this->paths as $path) {
            foreach (glob(rtrim($path, '/') . '/*.php') ?: [] as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                if (isset($migrations[$name]) && $migrations[$name] !== $file) {
                    throw MigrationException::duplicate($name, $migrations[$name], $file);
                }
                $migrations[$name] = $file;
            }
        }

        ksort($migrations, SORT_STRING);

        return $migrations;
    }

    private function getChecksum(string $path): string
    {
        return hash_file('sha256', $path) ?: '';
    }

    private function lock(): MigrationLock
    {
        return new MigrationLock($this->connection, $this->lockTimeout);
    }
}
