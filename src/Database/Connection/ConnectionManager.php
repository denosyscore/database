<?php

declare(strict_types=1);

namespace Denosys\Database\Connection;

use Denosys\Database\Query\Builder;
use Denosys\Database\Exceptions\DatabaseException;

class ConnectionManager
{
    /**
     * The connection factory.
     */
    protected ConnectionFactory $factory;

    /**
     * The active connection instances.
     * @var array<string, Connection>
     */
    protected array $connections = [];

    /**
     * The connection configurations.
     * @var array<string, array<string, mixed>>
     */
    protected array $configurations = [];

    /**
     * The default connection name.
     */
    protected string $defaultConnection = 'default';

    /**
     * Create a new connection manager instance.
     */
    public function __construct(?ConnectionFactory $factory = null)
    {
        $this->factory = $factory ?? new ConnectionFactory();
    }

    /**
     * Get a database connection instance.
     *
     * @param string|null $name Connection name (null for default)
     * @return Connection
     * @throws DatabaseException
     */
    public function connection(?string $name = null): Connection
    {
        $name = $name ?? $this->defaultConnection;

        // Return existing connection if available
        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        // Create new connection
        $this->connections[$name] = $this->makeConnection($name);

        return $this->connections[$name];
    }

    /**
     * Create a new connection instance.
     */
    protected function makeConnection(string $name): Connection
    {
        $config = $this->getConfig($name);

        if (empty($config)) {
            throw new DatabaseException("Database connection [{$name}] not configured.");
        }

        return $this->factory->make($config);
    }

    /**
     * Get the configuration for a connection.
     *
     * @return array<string, mixed>
     */
    public function getConfig(string $name): array
    {
        return $this->configurations[$name] ?? [];
    }

    /**
     * Add a connection configuration.
     *
     * @param string $name
     * @param array<string, mixed> $config
     */
    public function addConnection(string $name, array $config): void
    {
        $this->configurations[$name] = $config;
    }

    /**
     * Set the default connection name.
     */
    public function setDefaultConnection(string $name): void
    {
        $this->defaultConnection = $name;
    }

    /**
     * Get the default connection name.
     */
    public function getDefaultConnection(): string
    {
        return $this->defaultConnection;
    }

    /**
     * Disconnect from the given connection.
     */
    public function disconnect(?string $name = null): void
    {
        $name = $name ?? $this->defaultConnection;

        if (isset($this->connections[$name])) {
            $this->connections[$name]->disconnect();
            unset($this->connections[$name]);
        }
    }

    /**
     * Disconnect from all connections.
     */
    public function disconnectAll(): void
    {
        foreach (array_keys($this->connections) as $name) {
            $this->disconnect($name);
        }
    }

    /**
     * Reconnect to the given connection.
     */
    public function reconnect(?string $name = null): Connection
    {
        $name = $name ?? $this->defaultConnection;

        $this->disconnect($name);

        return $this->connection($name);
    }

    /**
     * Get all connection names.
     *
     * @return array<string>
     */
    public function getConnectionNames(): array
    {
        return array_keys($this->configurations);
    }

    /**
     * Check if a connection exists (configured).
     */
    public function hasConnection(string $name): bool
    {
        return isset($this->configurations[$name]);
    }

    /**
     * Check if a connection is currently active.
     */
    public function isConnected(string $name): bool
    {
        return isset($this->connections[$name]);
    }

    /**
     * Get the number of active connections.
     */
    public function getActiveConnectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * Begin a fluent query against a database table.
     */
    public function table(string $table, ?string $connection = null): Builder
    {
        return $this->connection($connection)->table($table);
    }

    /**
     * Get a new query builder instance.
     */
    public function query(?string $connection = null): Builder
    {
        return $this->connection($connection)->query();
    }

    /**
     * Run a select statement against the database.
     *
     * @param array<int|string, mixed> $bindings
     * @return array<array<string, mixed>>
     */
    public function select(string $query, array $bindings = [], ?string $connection = null): array
    {
        return $this->connection($connection)->select($query, $bindings);
    }

    /**
     * Run an insert statement against the database.
     *
     * @param array<int|string, mixed> $bindings
     */
    public function insert(string $query, array $bindings = [], ?string $connection = null): int|string
    {
        return $this->connection($connection)->insert($query, $bindings);
    }

    /**
     * Run an update statement against the database.
     *
     * @param array<int|string, mixed> $bindings
     */
    public function update(string $query, array $bindings = [], ?string $connection = null): int
    {
        return $this->connection($connection)->update($query, $bindings);
    }

    /**
     * Run a delete statement against the database.
     *
     * @param array<int|string, mixed> $bindings
     */
    public function delete(string $query, array $bindings = [], ?string $connection = null): int
    {
        return $this->connection($connection)->delete($query, $bindings);
    }

    /**
     * Execute a raw SQL statement.
     *
     * @param array<int|string, mixed> $bindings
     */
    public function statement(string $query, array $bindings = [], ?string $connection = null): bool
    {
        return $this->connection($connection)->statement($query, $bindings);
    }

    /**
     * Run a callback within a transaction.
     */
    public function transaction(callable $callback, ?string $connection = null): mixed
    {
        return $this->connection($connection)->transaction($callback);
    }

    /**
     * Purge all connections.
     */
    public function purge(): void
    {
        $this->disconnectAll();
        $this->configurations = [];
    }

    /**
     * Get the connection factory.
     */
    public function getFactory(): ConnectionFactory
    {
        return $this->factory;
    }
}
