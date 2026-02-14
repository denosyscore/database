<?php

declare(strict_types=1);

namespace Denosys\Database;

use Denosys\Database\Connection\Connection;
use Denosys\Database\Connection\ConnectionManager;
use Denosys\Database\Query\Builder;

abstract class Repository
{
    /**
     * The table name.
     */
    protected string $table;

    /**
     * The primary key column.
     */
    protected string $primaryKey = 'id';

    /**
     * The connection name (null for default).
     */
    protected ?string $connection = null;

    /**
     * The connection manager.
     */
    protected ConnectionManager $connectionManager;

    /**
     * Create a new repository instance.
     */
    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connectionManager = $connectionManager;
    }

    /**
     * Get the database connection.
     */
    protected function getConnection(): Connection
    {
        return $this->connectionManager->connection($this->connection);
    }

    /**
     * Get a new query builder for the repository's table.
     */
    protected function query(): Builder
    {
        return $this->getConnection()->table($this->table);
    }

    /**
     * Find a record by its primary key.
     */
    public function find(int|string $id): ?object
    {
        return $this->query()->find($id, $this->primaryKey);
    }

    /**
     * Find a record by its primary key or throw an exception.
     */
    public function findOrFail(int|string $id): object
    {
        $result = $this->find($id);

        if ($result === null) {
            throw new \RuntimeException("Record not found in {$this->table}: {$id}");
        }

        return $result;
    }

    /**
     * Get all records.
     *
     * @return array<object>
     */
    public function all(): array
    {
        return $this->query()->get();
    }

    /**
     * Get records matching the given criteria.
      * @param array<string, mixed> $criteria
      * @param array<string, string> $orderBy
      * @return array<\Denosys\Database\Model>
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        $query = $this->query();

        foreach ($criteria as $column => $value) {
            if (is_array($value)) {
                $query->whereIn($column, $value);
            } elseif ($value === null) {
                $query->whereNull($column);
            } else {
                $query->where($column, '=', $value);
            }
        }

        if ($orderBy !== null) {
            foreach ($orderBy as $column => $direction) {
                $query->orderBy($column, $direction);
            }
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        if ($offset !== null) {
            $query->offset($offset);
        }

        return $query->get();
    }

    /**
     * Find a single record matching the given criteria.
      * @param array<string, mixed> $criteria
     */
    public function findOneBy(array $criteria): ?object
    {
        $results = $this->findBy($criteria, null, 1);

        return $results[0] ?? null;
    }

    /**
     * Get the count of records matching the given criteria.
      * @param array<string, mixed> $criteria
     */
    public function count(array $criteria = []): int
    {
        $query = $this->query();

        foreach ($criteria as $column => $value) {
            if (is_array($value)) {
                $query->whereIn($column, $value);
            } elseif ($value === null) {
                $query->whereNull($column);
            } else {
                $query->where($column, '=', $value);
            }
        }

        return $query->count();
    }

    /**
     * Check if records exist matching the given criteria.
      * @param array<string, mixed> $criteria
     */
    public function exists(array $criteria): bool
    {
        return $this->count($criteria) > 0;
    }

    /**
     * Create a new record.
      * @param array<string, mixed> $data
     */
    public function create(array $data): int|string
    {
        return $this->query()->insert($data);
    }

    /**
     * Update a record by its primary key.
      * @param array<string, mixed> $data
     */
    public function update(int|string $id, array $data): int
    {
        return $this->query()
            ->where($this->primaryKey, '=', $id)
            ->update($data);
    }

    /**
     * Update records matching the given criteria.
      * @param array<string, mixed> $criteria
      * @param array<string, mixed> $data
     */
    public function updateBy(array $criteria, array $data): int
    {
        $query = $this->query();

        foreach ($criteria as $column => $value) {
            $query->where($column, '=', $value);
        }

        return $query->update($data);
    }

    /**
     * Delete a record by its primary key.
     */
    public function delete(int|string $id): int
    {
        return $this->query()
            ->where($this->primaryKey, '=', $id)
            ->delete();
    }

    /**
     * Delete records matching the given criteria.
      * @param array<string, mixed> $criteria
     */
    public function deleteBy(array $criteria): int
    {
        $query = $this->query();

        foreach ($criteria as $column => $value) {
            $query->where($column, '=', $value);
        }

        return $query->delete();
    }

    /**
     * Get paginated results.
     *
     * @param array<string, mixed> $criteria
     * @param array<string, string>|null $orderBy
     * @return array<string, mixed>
     */
    public function paginate(int $page = 1, int $perPage = 15, array $criteria = [], ?array $orderBy = null): array
    {
        $total = $this->count($criteria);
        $totalPages = (int) ceil($total / $perPage);
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        $data = $this->findBy($criteria, $orderBy, $perPage, $offset);

        return [
            'data' => $data,
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => $totalPages,
                'from' => $offset + 1,
                'to' => min($offset + $perPage, $total),
            ],
        ];
    }

    /**
     * Execute a callback within a transaction.
     */
    public function transaction(callable $callback): mixed
    {
        return $this->getConnection()->transaction($callback);
    }

    /**
     * Get the raw query builder for complex queries.
     */
    public function newQuery(): Builder
    {
        return $this->query();
    }
}
