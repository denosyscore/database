<?php

declare(strict_types=1);

namespace Denosys\Database\Query;

use Closure;
use Denosys\Database\Connection\Connection;
use Denosys\Database\Contracts\GrammarInterface;
use Denosys\Database\Contracts\QueryBuilderInterface;
use Generator;
use InvalidArgumentException;
use LogicException;

/**
 * An immutable SQL query. Every method that adds to the query returns a new
 * query and leaves the one it was called on unchanged, so a query can be
 * shared and branched safely:
 *
 *     $active = $db->table('users')->where('banned', '=', 0);
 *     $admins = $active->where('admin', '=', 1);   // $active is unchanged
 *
 * The result must be used: `$query->where(...);` on its own line does
 * nothing. PHP 8.5 warns about it and PHPStan reports it (#[\NoDiscard]).
 *
 * Every string is quoted as an identifier. Raw SQL is written with
 * Sql::raw() so it is visible in review; values are always bound.
 *
 * @phpstan-consistent-constructor
 */
class Builder implements QueryBuilderInterface
{
    protected Connection $connection;

    protected GrammarInterface $grammar;

    protected ?string $table = null;

    /** @var list<string|Expression> */
    protected array $columns = ['*'];

    protected bool $distinct = false;

    /** @var list<array<string, mixed>> */
    protected array $wheres = [];

    /** @var list<array{column: string|Expression, direction: string}> */
    protected array $orders = [];

    /** @var list<string|Expression> */
    protected array $groups = [];

    /** @var list<array<string, mixed>> */
    protected array $havings = [];

    /** @var list<array<string, mixed>> */
    protected array $joins = [];

    protected ?int $limitValue = null;

    protected ?int $offsetValue = null;

    /** 'update', 'shared' or null. */
    protected ?string $lock = null;

    /** @var list<string> */
    protected array $operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=', '<=>',
        'like', 'like binary', 'not like', 'ilike',
        '&', '|', '^', '<<', '>>', '&~',
        'rlike', 'not rlike', 'regexp', 'not regexp',
        '~', '~*', '!~', '!~*', 'similar to', 'not similar to',
        'not ilike', '~~*', '!~~*',
    ];

    /** @var array<string, list<mixed>> */
    protected array $bindings = [
        'select' => [],
        'from' => [],
        'join' => [],
        'where' => [],
        'groupBy' => [],
        'having' => [],
        'order' => [],
    ];

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->grammar = $connection->getGrammar();
    }

    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function table(string $table): static
    {
        $query = clone $this;
        $query->table = $table;

        return $query;
    }

    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function from(string $table): static
    {
        return $this->table($table);
    }

    /**
     * @param string|Expression|array<string|Expression> $columns
     */
    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function select(string|Expression|array $columns = ['*']): static
    {
        $query = clone $this;
        $query->columns = array_values(is_array($columns) ? $columns : [$columns]);
        $query->assertIdentifiers($query->columns);

        return $query;
    }

    /**
     * @param string|Expression|array<string|Expression> $columns
     */
    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function addSelect(string|Expression|array $columns): static
    {
        $columns = array_values(is_array($columns) ? $columns : [$columns]);
        $this->assertIdentifiers($columns);

        $query = clone $this;
        $query->columns = $query->columns === ['*'] ? $columns : [...$query->columns, ...$columns];

        return $query;
    }

    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function distinct(): static
    {
        $query = clone $this;
        $query->distinct = true;

        return $query;
    }

    /**
     * Add a comparison, or a parenthesised group when given a callback. The
     * callback receives an empty query and must return it with its conditions:
     *
     *     ->where(fn (Builder $q) => $q->where('a', '=', 1)->orWhere('b', '=', 2))
     *
     * Two arguments compare with '='. A null value compiles to IS NULL (with
     * '=') or IS NOT NULL (with '!=' or '<>').
     *
     * @param string|(Closure(static): mixed) $column
     */
    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function where(string|Closure $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        if ($column instanceof Closure) {
            return $this->whereNested($column, $boolean);
        }

        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $operator = $this->normalizeOperator($operator);

        if ($value === null) {
            return match ($operator) {
                '=' => $this->whereNull($column, $boolean),
                '!=', '<>' => $this->whereNull($column, $boolean, true),
                default => throw new InvalidArgumentException(
                    "Cannot compare column [{$column}] with null using [{$operator}]; use whereNull() or whereNotNull()."
                ),
            };
        }

        $this->assertIdentifiers([$column]);

        return $this->addWhere(['type' => 'basic', 'column' => $column, 'operator' => $operator, 'boolean' => $boolean], [$value]);
    }

    /**
     * @param string|(Closure(static): mixed) $column
     */
    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function orWhere(string|Closure $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return $this->where($column, $operator, $value, 'or');
    }

    /**
     * @param Closure(static): mixed $callback
     */
    protected function whereNested(Closure $callback, string $boolean): static
    {
        $group = $callback($this->emptyConditions());

        if (!$group instanceof self) {
            throw new LogicException(
                'A nested where callback must return the query it receives with its conditions added, '
                . 'for example fn (Builder $q) => $q->where(...)->orWhere(...). Queries are immutable.'
            );
        }

        if ($group->wheres === []) {
            return $this;
        }

        return $this->addWhere(['type' => 'nested', 'query' => $group->wheres, 'boolean' => $boolean], $group->bindings['where']);
    }

    /**
     * Compare a column with a list of values, or with the rows a subquery
     * selects. An empty list matches no rows (NOT IN: every row).
     *
     * @param array<int|string, mixed>|self $values
     */
    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function whereIn(string $column, array|self $values, string $boolean = 'and', bool $not = false): static
    {
        $this->assertIdentifiers([$column]);

        if ($values instanceof self) {
            return $this->addWhere(
                ['type' => $not ? 'notInSub' : 'inSub', 'column' => $column, 'query' => $values, 'boolean' => $boolean],
                $values->getBindings(),
            );
        }

        $values = array_values($values);

        return $this->addWhere(['type' => $not ? 'notIn' : 'in', 'column' => $column, 'values' => $values, 'boolean' => $boolean], $values);
    }

    /**
     * @param array<int|string, mixed>|self $values
     */
    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function whereNotIn(string $column, array|self $values, string $boolean = 'and'): static
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function whereNull(string $column, string $boolean = 'and', bool $not = false): static
    {
        $this->assertIdentifiers([$column]);

        return $this->addWhere(['type' => $not ? 'notNull' : 'null', 'column' => $column, 'boolean' => $boolean]);
    }

    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function whereNotNull(string $column, string $boolean = 'and'): static
    {
        return $this->whereNull($column, $boolean, true);
    }

    /**
     * @param array<int|string, mixed> $values Exactly two bounds.
     */
    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function whereBetween(string $column, array $values, string $boolean = 'and', bool $not = false): static
    {
        $this->assertIdentifiers([$column]);
        $values = array_values($values);
        if (count($values) !== 2) {
            throw new InvalidArgumentException('whereBetween() needs exactly two bounds.');
        }

        return $this->addWhere(['type' => 'between', 'column' => $column, 'not' => $not, 'boolean' => $boolean], $values);
    }

    /**
     * Compare two columns: whereColumn('posts.user_id', '=', 'users.id').
     */
    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function whereColumn(string $first, string $operator, string $second, string $boolean = 'and'): static
    {
        $this->assertIdentifiers([$first, $second]);

        return $this->addWhere([
            'type' => 'column',
            'first' => $first,
            'operator' => $this->normalizeOperator($operator),
            'second' => $second,
            'boolean' => $boolean,
        ]);
    }

    /**
     * Match rows for which the subquery returns a row. Correlate it with
     * whereColumn(): whereExists($posts->whereColumn('posts.user_id', '=', 'users.id')).
     */
    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function whereExists(self $query, string $boolean = 'and', bool $not = false): static
    {
        return $this->addWhere(['type' => $not ? 'notExists' : 'exists', 'query' => $query, 'boolean' => $boolean], $query->getBindings());
    }

    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function whereNotExists(self $query, string $boolean = 'and'): static
    {
        return $this->whereExists($query, $boolean, true);
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'and'): static
    {
        return $this->addWhere(['type' => 'raw', 'sql' => $sql, 'boolean' => $boolean], array_values($bindings));
    }

    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function join(string $table, string $first, string $operator, string $second, string $type = 'inner'): static
    {
        $this->assertIdentifiers([$first, $second]);
        if (!in_array($type, ['inner', 'left', 'right'], true)) {
            throw new InvalidArgumentException("Unsupported join type [{$type}].");
        }

        $query = clone $this;
        $query->joins[] = [
            'table' => $table,
            'first' => $first,
            'operator' => $this->normalizeOperator($operator),
            'second' => $second,
            'type' => $type,
        ];

        return $query;
    }

    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function rightJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'right');
    }

    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function orderBy(string|Expression $column, string $direction = 'asc'): static
    {
        $this->assertIdentifiers([$column]);
        $direction = strtolower($direction);
        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new InvalidArgumentException("Order direction must be 'asc' or 'desc', [{$direction}] given.");
        }

        $query = clone $this;
        $query->orders[] = ['column' => $column, 'direction' => $direction];

        return $query;
    }

    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function orderByDesc(string|Expression $column): static
    {
        return $this->orderBy($column, 'desc');
    }

    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function latest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'desc');
    }

    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function oldest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'asc');
    }

    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function groupBy(string|Expression ...$columns): static
    {
        $columns = array_values($columns);
        $this->assertIdentifiers($columns);

        $query = clone $this;
        $query->groups = [...$query->groups, ...$columns];

        return $query;
    }

    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function having(string|Expression $column, string $operator, mixed $value, string $boolean = 'and'): static
    {
        $this->assertIdentifiers([$column]);

        $query = clone $this;
        $query->havings[] = [
            'column' => $column,
            'operator' => $this->normalizeOperator($operator),
            'boolean' => $boolean,
        ];
        $query->bindings['having'][] = $value;

        return $query;
    }

    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function limit(int $limit): static
    {
        $query = clone $this;
        $query->limitValue = max(0, $limit);

        return $query;
    }

    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function take(int $limit): static
    {
        return $this->limit($limit);
    }

    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function offset(int $offset): static
    {
        $query = clone $this;
        $query->offsetValue = max(0, $offset);

        return $query;
    }

    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function skip(int $offset): static
    {
        return $this->offset($offset);
    }

    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function forPage(int $page, int $perPage = 15): static
    {
        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }

    /**
     * Lock the selected rows until the transaction ends (FOR UPDATE). SQLite
     * has no row locks: it serialises writers per database, so this adds
     * nothing there.
     */
    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function lockForUpdate(): static
    {
        $query = clone $this;
        $query->lock = 'update';

        return $query;
    }

    /**
     * Block other writers to the selected rows until the transaction ends
     * (FOR SHARE / LOCK IN SHARE MODE). Adds nothing on SQLite.
     */
    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function sharedLock(): static
    {
        $query = clone $this;
        $query->lock = 'shared';

        return $query;
    }

    public function get(): mixed
    {
        return $this->connection->select($this->toSql(), $this->getBindings());
    }

    public function first(): ?object
    {
        return $this->limit(1)->get()[0] ?? null;
    }

    public function firstValue(string $column): mixed
    {
        return $this->first()?->$column;
    }

    public function find(int|string $id, string $primaryKey = 'id'): ?object
    {
        return $this->where($primaryKey, '=', $id)->first();
    }

    public function findOrFail(int|string $id, string $primaryKey = 'id'): object
    {
        return $this->find($id, $primaryKey) ?? throw new \RuntimeException("Record not found: {$id}");
    }

    public function value(string $column): mixed
    {
        $name = $this->resultName($column);

        return $this->select($column)->first()?->$name;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $query = $this->select($key ? [$key, $column] : [$column]);
        $results = $this->connection->select($query->toSql(), $query->getBindings());
        $name = $this->resultName($column);

        if ($key === null) {
            return array_map(fn (object $row): mixed => $row->$name, $results);
        }

        $keyName = $this->resultName($key);
        $output = [];
        foreach ($results as $row) {
            $output[$row->$keyName] = $row->$name;
        }

        return $output;
    }

    /**
     * Walk every matching row in primary-key order, $chunkSize rows per
     * query, without holding them all in memory. Each query continues after
     * the last key seen, so rows inserted or deleted meanwhile do not shift
     * the pages. $alias is the key's name in the result when $column is
     * qualified ('users.id'). It yields database rows, not models.
     *
     * @return Generator<int, object>
     */
    public function lazyById(int $chunkSize = 1000, string $column = 'id', ?string $alias = null): Generator
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('The chunk size must be at least 1.');
        }
        if ($this->orders !== [] || $this->limitValue !== null || $this->offsetValue !== null) {
            throw new LogicException('lazyById() orders and pages by the key itself; remove orderBy(), limit() and offset().');
        }

        $alias ??= $this->resultName($column);
        $last = null;

        // Group the caller's conditions, so an orWhere() cannot bypass the cursor.
        $base = $this->wheres === []
            ? $this
            : $this->emptyConditions()->addWhere(['type' => 'nested', 'query' => $this->wheres, 'boolean' => 'and'], $this->bindings['where']);

        do {
            $page = ($last === null ? $base : $base->where($column, '>', $last))->orderBy($column)->limit($chunkSize);
            $rows = $this->connection->select($page->toSql(), $page->getBindings());

            foreach ($rows as $row) {
                if (!property_exists($row, $alias)) {
                    throw new LogicException("lazyById() needs the key [{$alias}] in every row; add it to select().");
                }
                yield $row;
            }

            $last = $rows === [] ? null : end($rows)->$alias;
        } while (count($rows) === $chunkSize);
    }

    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate('count', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('max', $column);
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('min', $column);
    }

    public function sum(string $column): float|int
    {
        $sum = $this->aggregate('sum', $column);

        return is_int($sum) || is_float($sum) ? $sum : (is_numeric($sum) ? $sum + 0 : 0);
    }

    public function avg(string $column): float|int|null
    {
        $avg = $this->aggregate('avg', $column);

        return is_int($avg) || is_float($avg) ? $avg : (is_numeric($avg) ? $avg + 0 : null);
    }

    protected function aggregate(string $function, string $column): mixed
    {
        $this->assertIdentifiers([$column]);
        $wrapped = $column === '*' ? '*' : $this->grammar->wrap($column);
        $expression = new Expression("{$function}({$wrapped}) as aggregate");

        $query = clone $this;
        $query->orders = [];
        $query->bindings['order'] = [];
        $query->limitValue = null;
        $query->offsetValue = null;
        $query->lock = null;

        if ($query->groups !== []) {
            // Aggregate over the grouped rows rather than returning one group's value.
            $sql = "SELECT {$expression->getValue()} FROM ({$query->toSql()}) as aggregate_source";
        } else {
            $query->columns = [$expression];
            $query->bindings['select'] = [];
            $sql = $query->toSql();
        }

        return $this->connection->selectOne($sql, $query->getBindings())?->aggregate;
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    /**
     * @param array<string, mixed> $values
     */
    public function insert(array $values): int|string
    {
        if ($values === []) {
            return 0;
        }

        $sql = $this->grammar->compileInsert($this->requireTable(), array_keys($values));

        return $this->connection->insert($sql, array_values($values));
    }

    /**
     * @param array<string, mixed> $values
     */
    public function insertGetId(array $values, string $primaryKey = 'id'): int|string
    {
        return $this->insert($values);
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    public function insertBatch(array $records): bool
    {
        if ($records === []) {
            return true;
        }

        $columns = array_keys($records[0]);
        $sql = $this->grammar->compileInsertBatch($this->requireTable(), $columns, count($records));

        $bindings = [];
        foreach ($records as $record) {
            foreach ($columns as $column) {
                $bindings[] = $record[$column] ?? null;
            }
        }

        return $this->connection->statement($sql, $bindings);
    }

    /**
     * Update the matching rows. A value may be an Expression (Sql::raw()),
     * which is written into the SQL as it is.
     *
     * @param array<string, mixed> $values
     */
    public function update(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        $sets = [];
        $bindings = [];
        foreach ($values as $column => $value) {
            if ($value instanceof Expression) {
                $sets[] = $this->grammar->wrap($column) . ' = ' . $value->getValue();
            } else {
                $sets[] = $this->grammar->wrap($column) . ' = ?';
                $bindings[] = $value;
            }
        }

        $sql = 'UPDATE ' . $this->grammar->wrapTable($this->requireTable()) . ' SET ' . implode(', ', $sets);
        $where = $this->grammar->compileWheres($this->wheres);
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        return $this->connection->update(
            $this->appendWriteLimit($sql, 'UPDATE'),
            [...$bindings, ...$this->bindings['join'], ...$this->bindings['where']],
        );
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     */
    public function updateOrInsert(array $attributes, array $values = []): bool
    {
        $query = $this;
        foreach ($attributes as $key => $value) {
            $query = $query->where($key, '=', $value);
        }

        if ($query->exists()) {
            return $values === [] || $query->update($values) > 0;
        }

        $this->insert(array_merge($attributes, $values));

        return true;
    }

    /**
     * Insert a row, or update it when it conflicts on $conflictColumns, in
     * one statement (ON DUPLICATE KEY UPDATE on MySQL, ON CONFLICT elsewhere).
     *
     * @param array<string, mixed> $values
     * @param list<string> $conflictColumns
     * @param list<string> $updateColumns Defaults to every inserted column except the conflict columns.
     */
    public function upsert(array $values, array $conflictColumns, array $updateColumns = []): bool
    {
        if ($values === []) {
            return true;
        }

        $insertColumns = array_keys($values);
        if ($updateColumns === []) {
            $updateColumns = array_values(array_diff($insertColumns, $conflictColumns));
        }

        // MySQL ignores the conflict columns: ON DUPLICATE KEY uses every unique key.
        $sql = $this->grammar->compileUpsert($this->requireTable(), $insertColumns, $conflictColumns, $updateColumns);

        return $this->connection->statement($sql, array_values($values));
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        $wrapped = $this->grammar->wrap($column);

        return $this->update([$column => new Expression("{$wrapped} + {$amount}"), ...$extra]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        $wrapped = $this->grammar->wrap($column);

        return $this->update([$column => new Expression("{$wrapped} - {$amount}"), ...$extra]);
    }

    public function delete(): int
    {
        $sql = $this->appendWriteLimit(
            $this->grammar->compileDelete($this->requireTable(), $this->grammar->compileWheres($this->wheres)),
            'DELETE',
        );

        return $this->connection->delete($sql, $this->bindings['where']);
    }

    public function truncate(): bool
    {
        return $this->connection->unprepared($this->grammar->compileTruncate($this->requireTable()));
    }

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
            'lock' => $this->lock,
        ]);
    }

    /**
     * @return list<mixed>
     */
    public function getBindings(): array
    {
        return array_merge(...array_values($this->bindings));
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getGrammar(): GrammarInterface
    {
        return $this->grammar;
    }

    public function dd(): never
    {
        \Symfony\Component\VarDumper\VarDumper::dump(['sql' => $this->toSql(), 'bindings' => $this->getBindings()]);
        exit(1);
    }

    public function dump(): static
    {
        \Symfony\Component\VarDumper\VarDumper::dump(['sql' => $this->toSql(), 'bindings' => $this->getBindings()]);

        return $this;
    }

    /**
     * @param array<string, mixed> $where
     * @param list<mixed> $bindings
     */
    protected function addWhere(array $where, array $bindings = []): static
    {
        $query = clone $this;
        $query->wheres[] = $where;
        $query->bindings['where'] = [...$query->bindings['where'], ...$bindings];

        return $query;
    }

    /**
     * This query without its conditions, for building a nested group. It
     * keeps the subclass and its state so a typed callback receives what it
     * expects.
     */
    protected function emptyConditions(): static
    {
        $query = clone $this;
        $query->wheres = [];
        $query->bindings['where'] = [];

        return $query;
    }

    /**
     * @param list<string|Expression> $identifiers
     */
    protected function assertIdentifiers(array $identifiers): void
    {
        foreach ($identifiers as $identifier) {
            if (is_string($identifier) && str_contains($identifier, '(')) {
                throw new InvalidArgumentException(
                    "[{$identifier}] is an expression, not a column name. Write expressions with Sql::raw() "
                    . 'and never put user input in them.'
                );
            }
        }
    }

    protected function normalizeOperator(mixed $operator): string
    {
        $normalized = is_string($operator) ? strtolower(trim($operator)) : null;

        if ($normalized === null || !in_array($normalized, $this->operators, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported comparison operator [%s]; use whereIn(), whereNull() or whereRaw() for other comparisons.',
                is_scalar($operator) ? (string) $operator : get_debug_type($operator),
            ));
        }

        return $normalized;
    }

    /**
     * The name a selected column has in the result row: the alias of
     * 'a as b', the last segment of 'table.column', or the column itself.
     */
    protected function resultName(string $column): string
    {
        $parts = preg_split('/\s+as\s+/i', $column, 2) ?: [$column];
        if (isset($parts[1])) {
            return trim($parts[1]);
        }

        $dot = strrpos($column, '.');

        return $dot === false ? $column : substr($column, $dot + 1);
    }

    protected function requireTable(): string
    {
        return $this->table ?? throw new LogicException('The query has no table; call table() first.');
    }

    /**
     * Append ORDER BY and LIMIT to an UPDATE or DELETE where the driver supports it.
     */
    protected function appendWriteLimit(string $sql, string $statement): string
    {
        if ($this->orders === [] && $this->limitValue === null && $this->offsetValue === null) {
            return $sql;
        }

        $driver = $this->grammar->getDriverName();

        if ($driver !== 'mysql' || $this->offsetValue !== null) {
            throw new LogicException(
                "{$statement} with limit(), offset() or orderBy() is not supported by the {$driver} driver; "
                . 'select the primary keys first and use whereIn().'
            );
        }

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . $this->grammar->compileOrders($this->orders);
        }

        if ($this->limitValue !== null) {
            $sql .= ' LIMIT ' . $this->limitValue;
        }

        return $sql;
    }
}
