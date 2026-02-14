<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Connection;

use PDO;
use PDOStatement;
use PDOException;
use Closure;
use CFXP\Core\Database\Contracts\ConnectionInterface;
use CFXP\Core\Database\Contracts\GrammarInterface;
use CFXP\Core\Database\Query\Builder;
use CFXP\Core\Exceptions\DatabaseException;

class Connection implements ConnectionInterface
{
    /**
     * The active PDO connection.
     */
    protected PDO $pdo;

    /**
     * The query grammar implementation.
     */
    protected GrammarInterface $grammar;

    /**
     * The connection configuration.
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * The number of active transactions.
     */
    protected int $transactions = 0;

    /**
     * The query log.
     * @var array<int, array{query: string, bindings: array<int|string, mixed>, time: float}>
     */
    protected array $queryLog = [];

    /**
     * Whether to log queries.
     */
    protected bool $loggingQueries = false;

    /**
     * Create a new database connection instance.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(PDO $pdo, GrammarInterface $grammar, array $config = [])
    {
        $this->pdo = $pdo;
        $this->grammar = $grammar;
        $this->config = $config;

        // Set default PDO attributes for consistency
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /**
     * {@inheritdoc}
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * {@inheritdoc}
     */
    public function getDriverName(): string
    {
        return $this->grammar->getDriverName();
    }

    /**
     * {@inheritdoc}
     */
    public function getGrammar(): GrammarInterface
    {
        return $this->grammar;
    }

    /**
     * Get the connection configuration.
     */
    public function getConfig(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        return $this->config[$key] ?? null;
    }

    /**
     * Begin a fluent query against a database table.
     */
    public function table(string $table): Builder
    {
        return $this->query()->table($table);
    }

    /**
     * Get a new query builder instance.
     */
    public function query(): Builder
    {
        return new Builder($this);
    }

    /**
     * {@inheritdoc}
     *
     * @param array<int|string, mixed> $bindings
     * @return array<object>
     */
    public function select(string $query, array $bindings = []): array
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            $statement = $this->prepared($query);
            $this->bindValues($statement, $bindings);
            $statement->execute();

            return $statement->fetchAll();
        });
    }

    /**
     * Run a select statement and return a single result.
     *
     * @param array<int|string, mixed> $bindings
     */
    public function selectOne(string $query, array $bindings = []): ?object
    {
        $results = $this->select($query, $bindings);

        return $results[0] ?? null;
    }

    /**
     * {@inheritdoc}
     *
     * @param array<int|string, mixed> $bindings
     */
    public function insert(string $query, array $bindings = []): int|string
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            $statement = $this->prepared($query);
            $this->bindValues($statement, $bindings);
            $statement->execute();

            return $this->pdo->lastInsertId();
        });
    }

    /**
     * {@inheritdoc}
     *
     * @param array<int|string, mixed> $bindings
     */
    public function update(string $query, array $bindings = []): int
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            $statement = $this->prepared($query);
            $this->bindValues($statement, $bindings);
            $statement->execute();

            return $statement->rowCount();
        });
    }

    /**
     * {@inheritdoc}
     *
     * @param array<int|string, mixed> $bindings
     */
    public function delete(string $query, array $bindings = []): int
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            $statement = $this->prepared($query);
            $this->bindValues($statement, $bindings);
            $statement->execute();

            return $statement->rowCount();
        });
    }

    /**
     * {@inheritdoc}
     *
     * @param array<int|string, mixed> $bindings
     */
    public function statement(string $query, array $bindings = []): bool
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            $statement = $this->prepared($query);
            $this->bindValues($statement, $bindings);

            return $statement->execute();
        });
    }

    /**
     * Run a raw, unprepared query against the PDO connection.
     */
    public function unprepared(string $query): bool
    {
        return $this->run($query, [], function ($query) {
            return $this->pdo->exec($query) !== false;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function beginTransaction(): bool
    {
        // First transaction, start PDO transaction
        if ($this->transactions === 0) {
            try {
                $this->pdo->beginTransaction();
            } catch (PDOException $e) {
                throw new DatabaseException('Failed to begin transaction: ' . $e->getMessage(), 0, $e);
            }
        } elseif ($this->transactions >= 1) {
            // Nested transaction, create savepoint
            $savepoint = $this->grammar->compileSavepoint('trans_' . ($this->transactions + 1));
            $this->pdo->exec($savepoint);
        }

        $this->transactions++;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function commit(): bool
    {
        if ($this->transactions === 1) {
            // Only attempt commit if PDO is actually in a transaction
            // (MySQL DDL statements cause implicit commit, ending the transaction)
            if ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->commit();
                } catch (PDOException $e) {
                    throw new DatabaseException('Failed to commit transaction: ' . $e->getMessage(), 0, $e);
                }
            }
        }
        
        // For savepoints, standard behavior is that committing an inner transaction 
        // essentially does nothing (it just doesn't rollback), so we just decrement.

        $this->transactions = max(0, $this->transactions - 1);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function rollBack(): bool
    {
        if ($this->transactions === 1) {
            // Only attempt rollback if PDO is actually in a transaction
            // (MySQL DDL statements cause implicit commit, ending the transaction)
            if ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->rollBack();
                } catch (PDOException $e) {
                    throw new DatabaseException('Failed to rollback transaction: ' . $e->getMessage(), 0, $e);
                }
            }
        } elseif ($this->transactions > 1) {
            // Rollback to savepoint
            $savepoint = $this->grammar->compileSavepointRollback('trans_' . $this->transactions);
            $this->pdo->exec($savepoint);
        }

        $this->transactions = max(0, $this->transactions - 1);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function inTransaction(): bool
    {
        return $this->transactions > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /**
     * Get the current transaction level.
     */
    public function transactionLevel(): int
    {
        return $this->transactions;
    }

    /**
     * Run a SQL query and handle errors.
     *
     * @param array<int|string, mixed> $bindings
     */
    protected function run(string $query, array $bindings, Closure $callback): mixed
    {
        $start = microtime(true);

        try {
            $result = $callback($query, $bindings);
        } catch (PDOException $e) {
            throw new DatabaseException(
                "Query failed: {$e->getMessage()}\nSQL: {$query}",
                (int) $e->getCode(),
                $e
            );
        }

        $time = round((microtime(true) - $start) * 1000, 2);

        if ($this->loggingQueries) {
            $this->queryLog[] = [
                'query' => $query,
                'bindings' => $bindings,
                'time' => $time,
            ];
        }

        return $result;
    }

    /**
     * Get a prepared statement.
     */
    protected function prepared(string $query): PDOStatement
    {
        $statement = $this->pdo->prepare($query);

        if (!$statement) {
            throw new DatabaseException('Failed to prepare statement: ' . $query);
        }

        return $statement;
    }

    /**
     * Bind values to their parameters in the given statement.
     *
     * @param array<int|string, mixed> $bindings
     */
    protected function bindValues(PDOStatement $statement, array $bindings): void
    {
        foreach (array_values($bindings) as $index => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                is_null($value) => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };

            $statement->bindValue($index + 1, $value, $type);
        }
    }

    /**
     * Enable query logging.
     */
    public function enableQueryLog(): void
    {
        $this->loggingQueries = true;
    }

    /**
     * Disable query logging.
     */
    public function disableQueryLog(): void
    {
        $this->loggingQueries = false;
    }

    /**
     * Get the query log.
     *
     * @return array<int, array{query: string, bindings: array<int|string, mixed>, time: float}>
     */
    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    /**
     * Clear the query log.
     */
    public function flushQueryLog(): void
    {
        $this->queryLog = [];
    }

    /**
     * Disconnect from the database.
     */
    public function disconnect(): void
    {
        unset($this->pdo);
    }

    /**
     * Get the database name.
     */
    public function getDatabaseName(): ?string
    {
        return $this->config['database'] ?? null;
    }

    /**
     * Get the table prefix.
     */
    public function getTablePrefix(): string
    {
        return $this->config['prefix'] ?? '';
    }
}
