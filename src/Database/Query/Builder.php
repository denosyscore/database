<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Query;

use Closure;
use CFXP\Core\Database\Connection\Connection;
use CFXP\Core\Database\Contracts\GrammarInterface;
use CFXP\Core\Database\Contracts\QueryBuilderInterface;

/**
 * @phpstan-consistent-constructor
 */
class Builder implements QueryBuilderInterface
{
    /**
     * The database connection instance.
     */
    protected Connection $connection;

    /**
     * The grammar instance.
     */
    protected GrammarInterface $grammar;

    /**
     * The table being queried.
     */
    protected ?string $table = null;

    /**
     * The columns to be selected.
      * @var array<string>
     */
    protected array $columns = ['*'];

    /**
     * Indicates if the query should return distinct results.
     */
    protected bool $distinct = false;

    /**
     * The where constraints for the query.
     */
    /** @var array<string, mixed> */

    protected array $wheres = [];

    /**
     * The orderings for the query.
     */
    /** @var array<string, mixed> */

    protected array $orders = [];

    /**
     * The groupings for the query.
     */
    /** @var array<string, mixed> */

    protected array $groups = [];

    /**
     * The having constraints for the query.
     */
    /** @var array<string, mixed> */

    protected array $havings = [];

    /**
     * The joins for the query.
     */
    /** @var array<string, mixed> */

    protected array $joins = [];

    /**
     * The maximum number of records to return.
     */
    protected ?int $limitValue = null;

    /**
     * The number of records to skip.
     */
    protected ?int $offsetValue = null;

    /**
     * All of the available clause operators.
      * @var array<string>
     */
    protected array $operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=', '<=>',
        'like', 'like binary', 'not like', 'ilike',
        '&', '|', '^', '<<', '>>', '&~',
        'rlike', 'not rlike', 'regexp', 'not regexp',
        '~', '~*', '!~', '!~*', 'similar to', 'not similar to',
        'not ilike', '~~*', '!~~*',
    ];

    /**
     * The current query value bindings.
      * @var array<string, array<int|string, mixed>>
     */
    protected array $bindings = [
        'select' => [],
        'from' => [],
        'join' => [],
        'where' => [],
        'groupBy' => [],
        'having' => [],
        'order' => [],
    ];

    /**
     * Create a new query builder instance.
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->grammar = $connection->getGrammar();
    }

    /**
     * {@inheritdoc}
     */
    public function table(string $table): static
    {
        $this->table = $table;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function from(string $table): static
    {
        return $this->table($table);
    }

    /**
     * {@inheritdoc}
      * @param array<string> $columns
     */
    public function select(string|array $columns = ['*']): static
    {
        $this->columns = is_array($columns) ? $columns : func_get_args();

        return $this;
    }

    /**
     * {@inheritdoc}
      * @param array<string> $columns
     */
    public function addSelect(string|array $columns): static
    {
        $columns = is_array($columns) ? $columns : func_get_args();

        foreach ($columns as $column) {
            $this->columns[] = $column;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function distinct(): static
    {
        $this->distinct = true;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function where(string|Closure $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        // Handle nested where using closure
        if ($column instanceof Closure) {
            return $this->whereNested($column, $boolean);
        }

        // If only two arguments, assume '=' operator
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        // Validate operator
        $operator = strtolower($operator);
        if (!in_array($operator, $this->operators)) {
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
        ];

        $this->addBinding($value, 'where');

        return $this;
    }

    /**
     * Add a nested where statement to the query.
     */
    protected function whereNested(Closure $callback, string $boolean = 'and'): static
    {
        $query = new static($this->connection);
        $query->table($this->table);

        $callback($query);

        if (!empty($query->wheres)) {
            $this->wheres[] = [
                'type' => 'nested',
                'query' => $query->wheres,
                'boolean' => $boolean,
            ];

            $this->mergeBindings($query);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function orWhere(string|Closure $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return $this->where($column, $operator, $value, 'or');
    }

    /**
     * {@inheritdoc}
      * @param array<int|string, mixed> $values
     */
    public function whereIn(string $column, array $values, string $boolean = 'and', bool $not = false): static
    {
        $type = $not ? 'notIn' : 'in';

        $this->wheres[] = [
            'type' => $type,
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean,
        ];

        foreach ($values as $value) {
            $this->addBinding($value, 'where');
        }

        return $this;
    }

    /**
     * {@inheritdoc}
      * @param array<int|string, mixed> $values
     */
    public function whereNotIn(string $column, array $values, string $boolean = 'and'): static
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    /**
     * {@inheritdoc}
     */
    public function whereNull(string $column, string $boolean = 'and', bool $not = false): static
    {
        $type = $not ? 'notNull' : 'null';

        $this->wheres[] = [
            'type' => $type,
            'column' => $column,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function whereNotNull(string $column, string $boolean = 'and'): static
    {
        return $this->whereNull($column, $boolean, true);
    }

    /**
     * {@inheritdoc}
      * @param array<int|string, mixed> $values
     */
    public function whereBetween(string $column, array $values, string $boolean = 'and', bool $not = false): static
    {
        $this->wheres[] = [
            'type' => 'between',
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean,
            'not' => $not,
        ];

        $this->addBinding($values[0], 'where');
        $this->addBinding($values[1], 'where');

        return $this;
    }

    /**
     * {@inheritdoc}
      * @param array<int|string, mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'and'): static
    {
        $this->wheres[] = [
            'type' => 'raw',
            'sql' => $sql,
            'boolean' => $boolean,
        ];

        foreach ($bindings as $binding) {
            $this->addBinding($binding, 'where');
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function join(string $table, string $first, string $operator, string $second, string $type = 'inner'): static
    {
        $operator = strtolower($operator);
        if (!in_array($operator, $this->operators)) {
            $operator = '=';
        }

        $this->joins[] = [
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
            'type' => $type,
        ];

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    /**
     * {@inheritdoc}
     */
    public function rightJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'right');
    }

    /**
     * {@inheritdoc}
     */
    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        $this->orders[] = [
            'column' => $column,
            'direction' => $direction,
        ];

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Add a latest order by clause.
     */
    public function latest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Add an oldest order by clause.
     */
    public function oldest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'asc');
    }

    /**
     * {@inheritdoc}
     */
    public function groupBy(string ...$columns): static
    {
        $this->groups = array_merge($this->groups, $columns);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function having(string $column, string $operator, mixed $value, string $boolean = 'and'): static
    {
        $this->havings[] = [
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
        ];

        $this->addBinding($value, 'having');

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function limit(int $limit): static
    {
        $this->limitValue = max(0, $limit);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function take(int $limit): static
    {
        return $this->limit($limit);
    }

    /**
     * {@inheritdoc}
     */
    public function offset(int $offset): static
    {
        $this->offsetValue = max(0, $offset);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function skip(int $offset): static
    {
        return $this->offset($offset);
    }

    /**
     * Set the limit and offset for pagination.
     */
    public function forPage(int $page, int $perPage = 15): static
    {
        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }

    /**
     * {@inheritdoc}
     */
    public function get(): mixed
    {
        return $this->connection->select($this->toSql(), $this->getBindings());
    }

    /**
     * {@inheritdoc}
     */
    public function first(): ?object
    {
        return $this->limit(1)->get()[0] ?? null;
    }

    /**
     * Get a single column's value from the first result.
     */
    public function firstValue(string $column): mixed
    {
        $result = $this->first();

        return $result?->$column;
    }

    /**
     * {@inheritdoc}
     */
    public function find(int|string $id, string $primaryKey = 'id'): ?object
    {
        return $this->where($primaryKey, '=', $id)->first();
    }

    /**
     * Find a record or throw an exception.
     */
    public function findOrFail(int|string $id, string $primaryKey = 'id'): object
    {
        $result = $this->find($id, $primaryKey);

        if ($result === null) {
            throw new \RuntimeException("Record not found: {$id}");
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function value(string $column): mixed
    {
        $result = $this->select($column)->first();

        return $result?->$column;
    }

    /**
     * {@inheritdoc}
     */
    /**
     * @return array<string, mixed>
     */
public function pluck(string $column, ?string $key = null): array
    {
        $results = $this->select($key ? [$key, $column] : [$column])->get();

        if ($key === null) {
            return array_map(fn($row) => $row->$column, $results);
        }

        $output = [];
        foreach ($results as $row) {
            $output[$row->$key] = $row->$column;
        }

        return $output;
    }

    /**
     * {@inheritdoc}
     */
    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate('count', $column);
    }

    /**
     * {@inheritdoc}
     */
    public function max(string $column): mixed
    {
        return $this->aggregate('max', $column);
    }

    /**
     * {@inheritdoc}
     */
    public function min(string $column): mixed
    {
        return $this->aggregate('min', $column);
    }

    /**
     * {@inheritdoc}
     */
    public function sum(string $column): float|int
    {
        return $this->aggregate('sum', $column) ?? 0;
    }

    /**
     * {@inheritdoc}
     */
    public function avg(string $column): float|int|null
    {
        return $this->aggregate('avg', $column);
    }

    /**
     * Execute an aggregate function on the database.
     */
    protected function aggregate(string $function, string $column): mixed
    {
        $result = $this->select("{$function}({$column}) as aggregate")->first();

        return $result?->aggregate;
    }

    /**
     * {@inheritdoc}
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    /**
     * {@inheritdoc}
      * @param array<int|string, mixed> $values
     */
    public function insert(array $values): int|string
    {
        if (empty($values)) {
            return 0;
        }

        $columns = array_keys($values);
        $sql = $this->grammar->compileInsert($this->table, $columns);

        return $this->connection->insert($sql, array_values($values));
    }

    /**
     * Insert and get the record.
      * @param array<int|string, mixed> $values
     */
    public function insertGetId(array $values, string $primaryKey = 'id'): int|string
    {
        return $this->insert($values);
    }

    /**
     * {@inheritdoc}
      * @param array<array<string, mixed>> $records
     */
    public function insertBatch(array $records): bool
    {
        if (empty($records)) {
            return true;
        }

        $columns = array_keys($records[0]);
        $sql = $this->grammar->compileInsertBatch($this->table, $columns, count($records));

        $bindings = [];
        foreach ($records as $record) {
            foreach ($columns as $column) {
                $bindings[] = $record[$column] ?? null;
            }
        }

        return $this->connection->statement($sql, $bindings);
    }

    /**
     * {@inheritdoc}
      * @param array<int|string, mixed> $values
     */
    public function update(array $values): int
    {
        if (empty($values)) {
            return 0;
        }

        // Build SET clause - handle Expression objects specially
        $setClauses = [];
        $bindings = [];
        
        foreach ($values as $column => $value) {
            $wrappedColumn = $this->grammar->wrap($column);
            
            if ($value instanceof Expression) {
                // Expression objects get embedded directly in SQL
                $setClauses[] = "{$wrappedColumn} = {$value->getValue()}";
            } else {
                // Regular values use placeholders
                $setClauses[] = "{$wrappedColumn} = ?";
                $bindings[] = $value;
            }
        }
        
        $setClause = implode(', ', $setClauses);
        $whereClause = $this->grammar->compileWheres($this->wheres);
        
        $sql = "UPDATE {$this->grammar->wrapTable($this->table)} SET {$setClause}";
        
        if (!empty($whereClause)) {
            $sql .= ' WHERE ' . $whereClause;
        }

        $allBindings = array_merge($bindings, $this->getBindings());

        return $this->connection->update($sql, $allBindings);
    }

    /**
     * Insert or update a record.
      * @param array<string, mixed> $attributes
      * @param array<int|string, mixed> $values
     */
    public function updateOrInsert(array $attributes, array $values = []): bool
    {
        // Try to find existing record
        $query = clone $this;
        foreach ($attributes as $key => $value) {
            $query->where($key, '=', $value);
        }

        if ($query->exists()) {
            return $query->update($values) > 0;
        }

        return $this->insert(array_merge($attributes, $values)) !== false;
    }

    /**
     * Insert a new record or update an existing one using a single atomic SQL statement.
     *
     * Unlike updateOrInsert which uses SELECT followed by INSERT or UPDATE,
     * this method uses database-specific upsert syntax for atomicity and performance:
     * - MySQL: INSERT ... ON DUPLICATE KEY UPDATE
     * - PostgreSQL/SQLite: INSERT ... ON CONFLICT DO UPDATE
     *
     * @param array<int|string, mixed> $values The values to insert (column => value pairs)
     * @param array<string, mixed> $conflictColumns The columns that determine uniqueness (for ON CONFLICT)
     * @param array<string, mixed> $updateColumns Columns to update on conflict. If empty, defaults to all insert columns except conflict columns.
     * @return bool
     */
    /**
     * @param array<int|string, mixed> $values
     * @param array<string, mixed> $conflictColumns
     * @param array<string, mixed> $updateColumns
     */
    public function upsert(array $values, array $conflictColumns, array $updateColumns = []): bool
    {
        if (empty($values)) {
            return true;
        }

        $insertColumns = array_keys($values);

        // If no update columns specified, default to all insert columns except conflict columns
        if (empty($updateColumns)) {
            $updateColumns = array_diff($insertColumns, $conflictColumns);
        }

        // Get the appropriate SQL from the grammar
        $driverName = $this->grammar->getDriverName();
        
        if ($driverName === 'mysql') {
            // MySQL doesn't need conflict columns in the syntax
            $sql = $this->grammar->compileUpsert($this->table, $insertColumns, $updateColumns);
        } else {
            // PostgreSQL and SQLite use ON CONFLICT syntax
            $sql = $this->grammar->compileUpsert($this->table, $insertColumns, $conflictColumns, $updateColumns);
        }

        return $this->connection->statement($sql, array_values($values));
    }

    /**
     * Increment a column's value by a given amount.
      * @param array<string, mixed> $extra
     */
    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        $wrapped = $this->grammar->wrap($column);
        
        $columns = array_merge([$column => new Expression("{$wrapped} + {$amount}")], $extra);

        return $this->update($columns);
    }

    /**
     * Decrement a column's value by a given amount.
      * @param array<string, mixed> $extra
     */
    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        $wrapped = $this->grammar->wrap($column);
        
        $columns = array_merge([$column => new Expression("{$wrapped} - {$amount}")], $extra);

        return $this->update($columns);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(): int
    {
        $whereClause = $this->grammar->compileWheres($this->wheres);
        $sql = $this->grammar->compileDelete($this->table, $whereClause);

        return $this->connection->delete($sql, $this->getBindings());
    }

    /**
     * {@inheritdoc}
     */
    public function truncate(): bool
    {
        $sql = $this->grammar->compileTruncate($this->table);

        return $this->connection->unprepared($sql);
    }

    /**
     * {@inheritdoc}
     */
    public function toSql(): string
    {
        return $this->grammar->compileSelect([
            'columns' => $this->columns,
            'from' => $this->table,
            'distinct' => $this->distinct,
            'joins' => $this->joins,
            'wheres' => $this->wheres,
            'groups' => $this->groups,
            'havings' => $this->havings,
            'orders' => $this->orders,
            'limit' => $this->limitValue,
            'offset' => $this->offsetValue,
        ]);
    }

    /**
     * {@inheritdoc}
      * @return array<int|string, mixed>
     */
    public function getBindings(): array
    {
        return array_merge(...array_values($this->bindings));
    }

    /**
     * Add a binding to the query.
     */
    protected function addBinding(mixed $value, string $type = 'where'): static
    {
        if (!isset($this->bindings[$type])) {
            throw new \InvalidArgumentException("Invalid binding type: {$type}");
        }

        $this->bindings[$type][] = $value;

        return $this;
    }

    /**
     * Merge bindings from another query.
     */
    protected function mergeBindings(Builder $query): static
    {
        foreach ($query->bindings as $type => $bindings) {
            $this->bindings[$type] = array_merge($this->bindings[$type], $bindings);
        }

        return $this;
    }

    /**
     * Get the database connection.
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Get the query grammar.
     */
    public function getGrammar(): GrammarInterface
    {
        return $this->grammar;
    }

    /**
     * Clone the query without bindings.
      * @param array<string, mixed> $properties
     */
    public function cloneWithout(array $properties): static
    {
        $clone = clone $this;

        foreach ($properties as $property) {
            if (property_exists($clone, $property)) {
                $clone->$property = match (true) {
                    is_array($clone->$property) => [],
                    is_bool($clone->$property) => false,
                    default => null,
                };
            }
        }

        return $clone;
    }

    /**
     * Dump the SQL and bindings for debugging.
     */
    public function dd(): never
    {
        \Symfony\Component\VarDumper\VarDumper::dump([
            'sql' => $this->toSql(),
            'bindings' => $this->getBindings(),
        ]);
        exit(1);
    }

    /**
     * Dump the SQL and bindings and continue.
     */
    public function dump(): static
    {
        \Symfony\Component\VarDumper\VarDumper::dump([
            'sql' => $this->toSql(),
            'bindings' => $this->getBindings(),
        ]);

        return $this;
    }
}
