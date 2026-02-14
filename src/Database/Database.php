<?php

declare(strict_types=1);

namespace CFXP\Core\Database;

use CFXP\Core\Database\Connection\Connection;
use CFXP\Core\Database\Connection\ConnectionManager;
use CFXP\Core\Database\Query\Builder;
use CFXP\Core\Database\Query\Expression;

/**
 * Database facade for convenient static access.
 * 
 * Provides a clean static API for database operations.
 * Delegates all calls to the ConnectionManager.
 * 
 * Usage:
 *   DB::table('users')->where('active', 1)->get();
 *   DB::select('SELECT * FROM users WHERE id = ?', [1]);
 *   DB::transaction(function ($db) { ... });
 */
class Database
{
    /**
     * The connection manager instance.
     */
    protected static ?ConnectionManager $manager = null;

    /**
     * Set the connection manager.
     */
    public static function setConnectionManager(ConnectionManager $manager): void
    {
        static::$manager = $manager;
    }

    /**
     * Get the connection manager.
     */
    public static function getConnectionManager(): ConnectionManager
    {
        if (static::$manager === null) {
            throw new \RuntimeException('DB facade not initialized. Call DB::setConnectionManager() first.');
        }

        return static::$manager;
    }

    /**
     * Get a database connection.
     */
    public static function connection(?string $name = null): Connection
    {
        return static::getConnectionManager()->connection($name);
    }

    /**
     * Begin a fluent query against a database table.
     */
    public static function table(string $table, ?string $connection = null): Builder
    {
        return static::connection($connection)->table($table);
    }

    /**
     * Get a new query builder.
     */
    public static function query(?string $connection = null): Builder
    {
        return static::connection($connection)->query();
    }

    /**
     * Run a select statement against the database.
     *
     * @param array<mixed> $bindings
     * @return array<int, object>
     */
    public static function select(string $query, array $bindings = [], ?string $connection = null): array
    {
        return static::connection($connection)->select($query, $bindings);
    }

    /**
     * Run a select statement and get a single result.
     *
     * @param array<mixed> $bindings
     */
    public static function selectOne(string $query, array $bindings = [], ?string $connection = null): ?object
    {
        return static::connection($connection)->selectOne($query, $bindings);
    }

    /**
     * Run an insert statement against the database.
     *
     * @param array<mixed> $bindings
     */
    public static function insert(string $query, array $bindings = [], ?string $connection = null): int|string
    {
        return static::connection($connection)->insert($query, $bindings);
    }

    /**
     * Run an update statement against the database.
     *
     * @param array<mixed> $bindings
     */
    public static function update(string $query, array $bindings = [], ?string $connection = null): int
    {
        return static::connection($connection)->update($query, $bindings);
    }

    /**
     * Run a delete statement against the database.
     *
     * @param array<mixed> $bindings
     */
    public static function delete(string $query, array $bindings = [], ?string $connection = null): int
    {
        return static::connection($connection)->delete($query, $bindings);
    }

    /**
     * Execute a SQL statement.
     *
     * @param array<mixed> $bindings
     */
    public static function statement(string $query, array $bindings = [], ?string $connection = null): bool
    {
        return static::connection($connection)->statement($query, $bindings);
    }

    /**
     * Run an unprepared SQL statement.
     */
    public static function unprepared(string $query, ?string $connection = null): bool
    {
        return static::connection($connection)->unprepared($query);
    }

    /**
     * Begin a database transaction.
     */
    public static function beginTransaction(?string $connection = null): bool
    {
        return static::connection($connection)->beginTransaction();
    }

    /**
     * Commit a database transaction.
     */
    public static function commit(?string $connection = null): bool
    {
        return static::connection($connection)->commit();
    }

    /**
     * Rollback a database transaction.
     */
    public static function rollBack(?string $connection = null): bool
    {
        return static::connection($connection)->rollBack();
    }

    /**
     * Execute a callback within a transaction.
     */
    public static function transaction(callable $callback, ?string $connection = null): mixed
    {
        return static::connection($connection)->transaction($callback);
    }

    /**
     * Get the current transaction level.
     */
    public static function transactionLevel(?string $connection = null): int
    {
        return static::connection($connection)->transactionLevel();
    }

    /**
     * Create a new raw expression.
     */
    public static function raw(string $value): Expression
    {
        return new Expression($value);
    }

    /**
     * Enable query logging.
     */
    public static function enableQueryLog(?string $connection = null): void
    {
        static::connection($connection)->enableQueryLog();
    }

    /**
     * Disable query logging.
     */
    public static function disableQueryLog(?string $connection = null): void
    {
        static::connection($connection)->disableQueryLog();
    }

    /**
     * Get the query log.
      * @return array<int, array<string, mixed>>
     */
    public static function getQueryLog(?string $connection = null): array
    {
        return static::connection($connection)->getQueryLog();
    }

    /**
     * Reconnect to the database.
     */
    public static function reconnect(?string $connection = null): Connection
    {
        return static::getConnectionManager()->reconnect($connection);
    }

    /**
     * Disconnect from the database.
     */
    public static function disconnect(?string $connection = null): void
    {
        static::getConnectionManager()->disconnect($connection);
    }

    /**
     * Get all connection names.
     *
     * @return array<string>
     */
    public static function getConnections(): array
    {
        return static::getConnectionManager()->getConnectionNames();
    }

    /**
     * Purge all connections (for testing).
     */
    public static function purge(): void
    {
        static::getConnectionManager()->purge();
    }
}
