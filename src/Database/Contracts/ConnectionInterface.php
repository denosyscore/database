<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Contracts;

use PDO;

interface ConnectionInterface
{
    /**
     * Get the underlying PDO instance.
     */
    public function getPdo(): PDO;

    /**
     * Get the database driver name (mysql, pgsql, sqlite, etc.).
     */
    public function getDriverName(): string;

    /**
     * Get the grammar instance for this connection.
     */
    public function getGrammar(): GrammarInterface;

    /**
     * Run a select statement and return results.
     *
     * @param string $query
     * @param array<int|string, mixed> $bindings
     * @return array<string, mixed> */
    public function select(string $query, array $bindings = []): array;

    /**
     * Run an insert statement and return the last insert ID.
     *
     * @param string $query
     * @param array<int|string, mixed> $bindings
     * @return int|string
     */
    public function insert(string $query, array $bindings = []): int|string;

    /**
     * Run an update statement and return affected rows.
     *
     * @param string $query
     * @param array<int|string, mixed> $bindings
     * @return int
     */
    public function update(string $query, array $bindings = []): int;

    /**
     * Run a delete statement and return affected rows.
     *
     * @param string $query
     * @param array<int|string, mixed> $bindings
     * @return int
     */
    public function delete(string $query, array $bindings = []): int;

    /**
     * Execute a raw SQL statement.
     *
     * @param string $query
     * @param array<int|string, mixed> $bindings
     * @return bool
     */
    public function statement(string $query, array $bindings = []): bool;

    /**
     * Begin a database transaction.
     */
    public function beginTransaction(): bool;

    /**
     * Commit an active transaction.
     */
    public function commit(): bool;

    /**
     * Rollback an active transaction.
     */
    public function rollBack(): bool;

    /**
     * Check if currently in a transaction.
     */
    public function inTransaction(): bool;

    /**
     * Execute a callback within a transaction.
     *
     * @param callable $callback
     * @return mixed
     */
    public function transaction(callable $callback): mixed;
}
