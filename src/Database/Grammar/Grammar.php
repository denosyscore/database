<?php

declare(strict_types=1);

namespace Denosys\Database\Grammar;

use Denosys\Database\Contracts\GrammarInterface;

abstract class Grammar implements GrammarInterface
{
    /**
     * The keyword identifier wrapper format.
     */
    protected string $wrapper = '"%s"';

    /**
     * The components that make up a select clause.
      * @var array<string>
     */
    protected array $selectComponents = [
        'columns',
        'from',
        'joins',
        'wheres',
        'groups',
        'havings',
        'orders',
        'limit',
        'offset',
    ];

    /**
     * {@inheritdoc}
     */
    abstract public function getDriverName(): string;

    /**
     * {@inheritdoc}
     */
    public function wrap(string $value): string
    {
        // Don't wrap expressions or already wrapped values
        if ($value === '*' || str_contains($value, '(') || str_contains($value, ' as ')) {
            return $this->wrapAliasedValue($value);
        }

        // Handle table.column format
        if (str_contains($value, '.')) {
            return $this->wrapSegments(explode('.', $value));
        }

        return $this->wrapValue($value);
    }

    /**
     * Wrap a value with alias support.
     */
    protected function wrapAliasedValue(string $value): string
    {
        // Handle "column as alias" syntax
        if (stripos($value, ' as ') !== false) {
            $segments = preg_split('/\s+as\s+/i', $value);
            return $this->wrap($segments[0]) . ' as ' . $this->wrapValue($segments[1]);
        }

        return $value;
    }

    /**
     * Wrap segments of a value (e.g., table.column).
      * @param array<string> $segments
     */
    protected function wrapSegments(array $segments): string
    {
        return implode('.', array_map(
            fn($segment) => $segment === '*' ? $segment : $this->wrapValue($segment),
            $segments
        ));
    }

    /**
     * Wrap a single value in keyword identifiers.
     */
    protected function wrapValue(string $value): string
    {
        // Extract the wrapper character from format string (e.g., ` from `%s` or " from "%s")
        // The wrapper character is the first character of the format string
        $wrapperChar = $this->wrapper[0];
        
        // Escape the wrapper character by doubling it (SQL standard)
        // e.g., " becomes "" for PostgreSQL/SQLite, ` becomes `` for MySQL
        $escaped = str_replace($wrapperChar, $wrapperChar . $wrapperChar, $value);
        
        return sprintf($this->wrapper, $escaped);
    }

    /**
     * {@inheritdoc}
     */
    public function wrapTable(string $table): string
    {
        // Handle "table as alias" syntax
        if (stripos($table, ' as ') !== false) {
            return $this->wrapAliasedTable($table);
        }

        return $this->wrapValue($table);
    }

    /**
     * Wrap a table with alias support.
     */
    protected function wrapAliasedTable(string $table): string
    {
        $segments = preg_split('/\s+as\s+/i', $table);
        return $this->wrapValue($segments[0]) . ' as ' . $this->wrapValue($segments[1]);
    }

    /**
     * {@inheritdoc}
      * @param array<string, mixed> $components
     */
    public function compileSelect(array $components): string
    {
        $sql = [];

        foreach ($this->selectComponents as $component) {
            if (isset($components[$component]) && !empty($components[$component])) {
                $method = 'compile' . ucfirst($component);
                if (method_exists($this, $method)) {
                    $result = $this->$method($components[$component]);
                    if ($result !== '') {
                        $sql[$component] = $result;
                    }
                }
            }
        }

        return $this->concatenateSelect($sql, $components);
    }

    /**
     * Concatenate the select components into a single query.
      * @param array<string, string> $sql
      * @param array<string, mixed> $components
     */
    protected function concatenateSelect(array $sql, array $components): string
    {
        $query = 'SELECT ';
        
        if (isset($components['distinct']) && $components['distinct']) {
            $query .= 'DISTINCT ';
        }

        $query .= ($sql['columns'] ?? '*');

        if (isset($sql['from'])) {
            $query .= ' FROM ' . $sql['from'];
        }

        if (isset($sql['joins'])) {
            $query .= ' ' . $sql['joins'];
        }

        if (isset($sql['wheres'])) {
            $query .= ' WHERE ' . $sql['wheres'];
        }

        if (isset($sql['groups'])) {
            $query .= ' GROUP BY ' . $sql['groups'];
        }

        if (isset($sql['havings'])) {
            $query .= ' HAVING ' . $sql['havings'];
        }

        if (isset($sql['orders'])) {
            $query .= ' ORDER BY ' . $sql['orders'];
        }

        if (isset($sql['limit'])) {
            $query .= ' ' . $sql['limit'];
        }

        if (isset($sql['offset'])) {
            $query .= ' ' . $sql['offset'];
        }

        return trim($query);
    }

    /**
     * {@inheritdoc}
      * @param array<string> $columns
     */
    public function compileColumns(array $columns): string
    {
        return implode(', ', array_map(fn($col) => $this->wrap($col), $columns));
    }

    /**
     * Compile the FROM clause.
     */
    protected function compileFrom(string $table): string
    {
        return $this->wrapTable($table);
    }

    /**
     * {@inheritdoc}
      * @param array<int|string, mixed> $wheres
     */
    public function compileWheres(array $wheres): string
    {
        if (empty($wheres)) {
            return '';
        }

        $sql = [];

        foreach ($wheres as $index => $where) {
            $compiled = $this->compileWhere($where);
            
            // First where clause doesn't need a boolean
            if ($index === 0) {
                $sql[] = $compiled;
            } else {
                $sql[] = $where['boolean'] . ' ' . $compiled;
            }
        }

        return implode(' ', $sql);
    }

    /**
     * Compile a single where clause.
      * @param array<string, mixed> $where
     */
    protected function compileWhere(array $where): string
    {
        return match ($where['type']) {
            'basic' => $this->compileWhereBasic($where),
            'in' => $this->compileWhereIn($where),
            'notIn' => $this->compileWhereNotIn($where),
            'null' => $this->compileWhereNull($where),
            'notNull' => $this->compileWhereNotNull($where),
            'between' => $this->compileWhereBetween($where),
            'raw' => $where['sql'],
            'nested' => '(' . $this->compileWheres($where['query']) . ')',
            default => throw new \InvalidArgumentException("Unknown where type: {$where['type']}"),
        };
    }

    /**
     * Compile a basic where clause.
      * @param array<string, mixed> $where
     */
    protected function compileWhereBasic(array $where): string
    {
        return $this->wrap($where['column']) . ' ' . $where['operator'] . ' ?';
    }

    /**
     * Compile a "where in" clause.
      * @param array<string, mixed> $where
     */
    protected function compileWhereIn(array $where): string
    {
        $placeholders = $this->parameterize($where['values']);
        return $this->wrap($where['column']) . ' IN (' . $placeholders . ')';
    }

    /**
     * Compile a "where not in" clause.
      * @param array<string, mixed> $where
     */
    protected function compileWhereNotIn(array $where): string
    {
        $placeholders = $this->parameterize($where['values']);
        return $this->wrap($where['column']) . ' NOT IN (' . $placeholders . ')';
    }

    /**
     * Compile a "where null" clause.
      * @param array<string, mixed> $where
     */
    protected function compileWhereNull(array $where): string
    {
        return $this->wrap($where['column']) . ' IS NULL';
    }

    /**
     * Compile a "where not null" clause.
      * @param array<string, mixed> $where
     */
    protected function compileWhereNotNull(array $where): string
    {
        return $this->wrap($where['column']) . ' IS NOT NULL';
    }

    /**
     * Compile a "where between" clause.
      * @param array<string, mixed> $where
     */
    protected function compileWhereBetween(array $where): string
    {
        $not = $where['not'] ? 'NOT ' : '';
        return $this->wrap($where['column']) . ' ' . $not . 'BETWEEN ? AND ?';
    }

    /**
     * {@inheritdoc}
      * @param array<array<string, string>> $orders
     */
    public function compileOrders(array $orders): string
    {
        return implode(', ', array_map(
            fn($order) => $this->wrap($order['column']) . ' ' . strtoupper($order['direction']),
            $orders
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function compileLimit(?int $limit, ?int $offset = null): string
    {
        $sql = '';

        if ($limit !== null) {
            $sql = 'LIMIT ' . $limit;
        }

        if ($offset !== null && $offset > 0) {
            $sql .= ' OFFSET ' . $offset;
        }

        return $sql;
    }

    /**
     * Compile the offset for the select query.
     */
    protected function compileOffset(?int $offset): string
    {
        if ($offset !== null && $offset > 0) {
            return 'OFFSET ' . $offset;
        }
        
        return '';
    }

    /**
     * {@inheritdoc}
      * @param array<array<string, string>> $joins
     */
    public function compileJoins(array $joins): string
    {
        return implode(' ', array_map(
            fn($join) => strtoupper($join['type']) . ' JOIN ' . 
                         $this->wrapTable($join['table']) . ' ON ' . 
                         $this->wrap($join['first']) . ' ' . 
                         $join['operator'] . ' ' . 
                         $this->wrap($join['second']),
            $joins
        ));
    }

    /**
     * {@inheritdoc}
      * @param array<string> $groups
     */
    public function compileGroups(array $groups): string
    {
        return implode(', ', array_map(fn($col) => $this->wrap($col), $groups));
    }

    /**
     * {@inheritdoc}
      * @param array<array<string, mixed>> $havings
     */
    public function compileHavings(array $havings): string
    {
        $sql = [];

        foreach ($havings as $index => $having) {
            $compiled = $this->wrap($having['column']) . ' ' . $having['operator'] . ' ?';
            
            if ($index === 0) {
                $sql[] = $compiled;
            } else {
                $sql[] = $having['boolean'] . ' ' . $compiled;
            }
        }

        return implode(' ', $sql);
    }

    /**
     * {@inheritdoc}
      * @param array<string> $columns
     */
    public function compileInsert(string $table, array $columns): string
    {
        $columnList = implode(', ', array_map(fn($col) => $this->wrap($col), $columns));
        $placeholders = $this->parameterize($columns);

        return "INSERT INTO {$this->wrapTable($table)} ({$columnList}) VALUES ({$placeholders})";
    }

    /**
     * {@inheritdoc}
      * @param array<string> $columns
     */
    public function compileInsertBatch(string $table, array $columns, int $rowCount): string
    {
        $columnList = implode(', ', array_map(fn($col) => $this->wrap($col), $columns));
        $rowPlaceholders = '(' . $this->parameterize($columns) . ')';
        $allPlaceholders = implode(', ', array_fill(0, $rowCount, $rowPlaceholders));

        return "INSERT INTO {$this->wrapTable($table)} ({$columnList}) VALUES {$allPlaceholders}";
    }

    /**
     * {@inheritdoc}
      * @param array<string> $columns
     */
    public function compileUpdate(string $table, array $columns, string $where): string
    {
        $setClause = implode(', ', array_map(
            fn($col) => $this->wrap($col) . ' = ?',
            $columns
        ));

        $sql = "UPDATE {$this->wrapTable($table)} SET {$setClause}";

        if (!empty($where)) {
            $sql .= ' WHERE ' . $where;
        }

        return $sql;
    }

    /**
     * {@inheritdoc}
     */
    public function compileDelete(string $table, string $where): string
    {
        $sql = "DELETE FROM {$this->wrapTable($table)}";

        if (!empty($where)) {
            $sql .= ' WHERE ' . $where;
        }

        return $sql;
    }

    /**
     * {@inheritdoc}
     */
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s';
    }

    /**
     * {@inheritdoc}
     */
    public function compileTruncate(string $table): string
    {
        return 'TRUNCATE TABLE ' . $this->wrapTable($table);
    }

    /**
     * {@inheritdoc}
      * @param array<int|string, mixed> $values
     */
    public function parameterize(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * {@inheritdoc}
     */
    public function compileSavepoint(string $name): string
    {
        return 'SAVEPOINT ' . $name;
    }

    /**
     * {@inheritdoc}
     */
    public function compileSavepointRollback(string $name): string
    {
        return 'ROLLBACK TO SAVEPOINT ' . $name;
    }
}
