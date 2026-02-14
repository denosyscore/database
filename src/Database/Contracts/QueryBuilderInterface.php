<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Contracts;

interface QueryBuilderInterface
{
    /**
     * Set the table to query.
     */
    public function table(string $table): static;

    /**
     * Alias for table().
     */
    public function from(string $table): static;

    /**
     * Set the columns to select.
     *
     * @param string|array<string> $columns
      * @param array<string> $columns
     */
    public function select(string|array $columns = ['*']): static;

    /**
     * Add a column to the select clause.
     *
     * @param string|array<string> $columns
      * @param array<string> $columns
     */
    public function addSelect(string|array $columns): static;

    /**
     * Select distinct results.
     */
    public function distinct(): static;

    /**
     * Add a where clause.
     *
     * @param string|\Closure $column
     * @param mixed $operator
     * @param mixed $value
     * @param string $boolean
     */
    public function where(string|\Closure $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static;

    /**
     * Add an "or where" clause.
     */
    public function orWhere(string|\Closure $column, mixed $operator = null, mixed $value = null): static;

    /**
     * Add a "where in" clause.
     *
     * @param array<int|string, mixed> $values
     */
    public function whereIn(string $column, array $values, string $boolean = 'and', bool $not = false): static;

    /**
     * Add a "where not in" clause.
     *
     * @param array<int|string, mixed> $values
     */
    public function whereNotIn(string $column, array $values, string $boolean = 'and'): static;

    /**
     * Add a "where null" clause.
     */
    public function whereNull(string $column, string $boolean = 'and', bool $not = false): static;

    /**
     * Add a "where not null" clause.
     */
    public function whereNotNull(string $column, string $boolean = 'and'): static;

    /**
     * Add a "where between" clause.
     *
     * @param array{0: mixed, 1: mixed} $values
      * @param array<int|string, mixed> $values
     */
    public function whereBetween(string $column, array $values, string $boolean = 'and', bool $not = false): static;

    /**
     * Add a raw where clause.
     *
     * @param array<int|string, mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'and'): static;

    /**
     * Add a join clause.
     */
    public function join(string $table, string $first, string $operator, string $second, string $type = 'inner'): static;

    /**
     * Add a left join clause.
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): static;

    /**
     * Add a right join clause.
     */
    public function rightJoin(string $table, string $first, string $operator, string $second): static;

    /**
     * Add an "order by" clause.
     */
    public function orderBy(string $column, string $direction = 'asc'): static;

    /**
     * Order by descending.
     */
    public function orderByDesc(string $column): static;

    /**
     * Add a "group by" clause.
     */
    public function groupBy(string ...$columns): static;

    /**
     * Add a "having" clause.
     */
    public function having(string $column, string $operator, mixed $value, string $boolean = 'and'): static;

    /**
     * Set the limit.
     */
    public function limit(int $limit): static;

    /**
     * Alias for limit().
     */
    public function take(int $limit): static;

    /**
     * Set the offset.
     */
    public function offset(int $offset): static;

    /**
     * Alias for offset().
     */
    public function skip(int $offset): static;

    /**
     * Execute the query and get all results.
     */
    public function get(): mixed;

    /**
     * Execute the query and get the first result.
     */
    public function first(): ?object;

    /**
     * Find a record by its primary key.
     */
    public function find(int|string $id, string $primaryKey = 'id'): ?object;

    /**
     * Get the value of a single column.
     */
    public function value(string $column): mixed;

    /**
     * Get an array of values for a single column.
     *
     * @return array<int|string, mixed>
     */
    public function pluck(string $column, ?string $key = null): array;

    /**
     * Get the count of records.
     */
    public function count(string $column = '*'): int;

    /**
     * Get the max value of a column.
     */
    public function max(string $column): mixed;

    /**
     * Get the min value of a column.
     */
    public function min(string $column): mixed;

    /**
     * Get the sum of a column.
     */
    public function sum(string $column): float|int;

    /**
     * Get the average of a column.
     */
    public function avg(string $column): float|int|null;

    /**
     * Check if any records exist.
     */
    public function exists(): bool;

    /**
     * Check if no records exist.
     */
    public function doesntExist(): bool;

    /**
     * Insert a new record and return the ID.
     *
     * @param array<int|string, mixed> $values
     */
    public function insert(array $values): int|string;

    /**
     * Insert multiple records.
     *
     * @param array<array<string, mixed>> $records
      * @param array<array<string, mixed>> $records
     */
    public function insertBatch(array $records): bool;

    /**
     * Update records.
     *
     * @param array<int|string, mixed> $values
     */
    public function update(array $values): int;

    /**
     * Delete records.
     */
    public function delete(): int;

    /**
     * Truncate the table.
     */
    public function truncate(): bool;

    /**
     * Get the SQL query string.
     */
    public function toSql(): string;

    /**
     * Get the query bindings.
     *
     * @return array<int|string, mixed>
     */
    public function getBindings(): array;
}

