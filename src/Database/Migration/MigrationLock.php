<?php

declare(strict_types=1);

namespace Denosys\Database\Migration;

use Denosys\Database\Connection\Connection;

/**
 * Lets one migration run at a time per database, across processes and hosts.
 *
 * - MySQL and MariaDB: GET_LOCK(), held by the connection's session.
 * - PostgreSQL: a session advisory lock (pg_try_advisory_lock).
 * - SQLite: flock() on a file beside the database. An in-memory database
 *   belongs to one process, so it needs no lock.
 *
 * Session locks are released when the connection closes, so a crashed run
 * does not leave the database locked.
 *
 * @internal Used by Migrator.
 */
final class MigrationLock
{
    /** @var resource|null */
    private $file = null;

    private bool $held = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly float $timeout = 60.0,
    ) {
    }

    /**
     * Run $callback while holding the lock.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function hold(callable $callback): mixed
    {
        $this->acquire();

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    public function acquire(): void
    {
        $acquired = match ($this->connection->getDriverName()) {
            'mysql', 'mariadb' => $this->acquireMySql(),
            'pgsql' => $this->acquirePostgres(),
            'sqlite' => $this->acquireSqlite(),
            default => true,
        };

        if (!$acquired) {
            throw MigrationException::locked($this->databaseName(), $this->timeout);
        }

        $this->held = true;
    }

    public function release(): void
    {
        if (!$this->held) {
            return;
        }
        $this->held = false;

        match ($this->connection->getDriverName()) {
            'mysql', 'mariadb' => $this->connection->select('SELECT RELEASE_LOCK(?)', [$this->name()]),
            'pgsql' => $this->connection->select('SELECT pg_advisory_unlock(?)', [$this->key()]),
            default => null,
        };

        if (is_resource($this->file)) {
            flock($this->file, LOCK_UN);
            fclose($this->file);
            $this->file = null;
        }
    }

    private function acquireMySql(): bool
    {
        // GET_LOCK waits up to the timeout itself; it accepts whole seconds.
        $row = $this->connection->selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$this->name(), max(0, (int) ceil($this->timeout))]);

        return in_array($row !== null ? ((array) $row)['acquired'] ?? null : null, [1, '1'], true);
    }

    private function acquirePostgres(): bool
    {
        return $this->poll(function (): bool {
            $row = $this->connection->selectOne('SELECT pg_try_advisory_lock(?) AS acquired', [$this->key()]);

            return in_array($row !== null ? ((array) $row)['acquired'] ?? null : null, [true, 1, 't', '1'], true);
        });
    }

    private function acquireSqlite(): bool
    {
        $database = $this->connection->getConfig('database');
        if (!is_string($database) || $database === '' || $database === ':memory:' || str_contains($database, 'mode=memory')) {
            return true;
        }

        $file = fopen($database . '.migrating', 'c');
        if ($file === false) {
            throw new \RuntimeException("Cannot open the migration lock file beside [{$database}].");
        }
        $this->file = $file;

        return $this->poll(static fn (): bool => flock($file, LOCK_EX | LOCK_NB));
    }

    /**
     * @param callable(): bool $attempt
     */
    private function poll(callable $attempt): bool
    {
        $deadline = microtime(true) + $this->timeout;
        do {
            if ($attempt()) {
                return true;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    /** A lock name unique to this database, within MySQL's 64-character limit. */
    private function name(): string
    {
        return 'denosys_migrations_' . substr(sha1($this->databaseName()), 0, 20);
    }

    /** A signed 32-bit advisory lock key for this database. */
    private function key(): int
    {
        $hash = crc32('denosys_migrations:' . $this->databaseName());

        return $hash > 0x7FFFFFFF ? $hash - 0x100000000 : $hash;
    }

    private function databaseName(): string
    {
        $database = $this->connection->getConfig('database');

        return is_string($database) && $database !== '' ? $database : 'default';
    }
}
