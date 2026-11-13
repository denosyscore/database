<?php

declare(strict_types=1);

namespace Denosys\Database\Schema;

use Closure;
use Denosys\Database\Connection\Connection;
use Denosys\Database\Exceptions\DatabaseException;
use Denosys\Database\Schema\Grammar\SchemaGrammarInterface;
use Denosys\Database\Schema\Grammar\SqliteSchemaGrammar;
use LogicException;
use PDOException;

/**
 * Entry point for schema operations.
 * 
 * Provides fluent API for creating, modifying, and dropping tables.
 * 
 * @example
 * $schema = new SchemaBuilder($connection, $grammar);
 * $schema->create('users', function (Blueprint $table) {
 *     $table->id();
 *     $table->column('email', ColumnType::String)->unique();
 * });
 */
class SchemaBuilder
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SchemaGrammarInterface $grammar,
    ) {}

    /**
     * Create a new table.
     */
    public function create(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        // On SQLite and PostgreSQL, a failed index or key leaves no half-made table.
        $this->atomically($this->grammar->compileCreate($blueprint));
    }

    /**
     * Modify an existing table.
     */
    public function table(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        if ($this->grammar instanceof SqliteSchemaGrammar && SqliteSchemaGrammar::needsRebuild($blueprint)) {
            (new SqliteTableRebuild($this->connection, $this->grammar))->apply($blueprint);

            return;
        }

        $this->atomically($this->grammar->compileAlter($blueprint));
    }

    /**
     * Drop a table.
     */
    public function drop(string $table): void
    {
        $statement = $this->grammar->compileDrop($table);
        $this->connection->statement($statement);
    }

    /**
     * Drop a table if it exists.
     */
    public function dropIfExists(string $table): void
    {
        $statement = $this->grammar->compileDropIfExists($table);
        $this->connection->statement($statement);
    }

    /**
     * Rename a table.
     */
    public function rename(string $from, string $to): void
    {
        $statement = $this->grammar->compileRename($from, $to);
        $this->connection->statement($statement);
    }

    /**
     * Drop all tables in the database.
     */
    /**
     * Drop every table in the connection's database (PostgreSQL: in its
     * current schema only, in one statement and transaction).
     */
    public function dropAllTables(): void
    {
        $tables = $this->getTables();
        if ($tables === []) {
            return;
        }

        if ($this->connection->getDriverName() === 'pgsql') {
            $row = $this->connection->selectOne('SELECT current_schema() AS name');
            $schema = $row === null ? null : ((array) $row)['name'] ?? null;
            if (!is_string($schema)) {
                throw new LogicException('The connection has no current schema; check its search path.');
            }
            $quote = static fn (string $name): string => '"' . str_replace('"', '""', $name) . '"';
            $this->connection->transaction(fn () => $this->connection->statement(
                'DROP TABLE ' . implode(', ', array_map(static fn (string $table): string => $quote($schema) . '.' . $quote($table), $tables))
            ));

            return;
        }

        $this->connection->statement($this->grammar->compileDisableForeignKeyConstraints());
        try {
            foreach ($tables as $table) {
                $this->drop($table);
            }
        } finally {
            $this->connection->statement($this->grammar->compileEnableForeignKeyConstraints());
        }
    }

    /**
     * Check if a table exists.
     */
    public function hasTable(string $table): bool
    {
        $sql = $this->grammar->compileTableExists($table);
        $result = $this->connection->select($sql);
        return count($result) > 0;
    }

    /**
     * Check if a column exists in a table.
     */
    public function hasColumn(string $table, string $column): bool
    {
        $columns = $this->getColumns($table);
        return in_array(strtolower($column), array_map('strtolower', $columns), true);
    }

    /**
     * Check if columns exist in a table.
      * @param array<string> $columns
     */
    public function hasColumns(string $table, array $columns): bool
    {
        $tableColumns = array_map('strtolower', $this->getColumns($table));
        
        foreach ($columns as $column) {
            if (!in_array(strtolower($column), $tableColumns, true)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Inspect existing indexes, including their ordered columns and uniqueness.
     * Expression index positions have a null column name.
     *
     * @return list<array{name: string, columns: list<string|null>, unique: bool, primary: bool}>
     */
    public function getIndexes(string $table): array
    {
        return match ($this->connection->getDriverName()) {
            'sqlite' => $this->getSqliteIndexes($table),
            'mysql' => $this->getMySqlIndexes($table),
            'pgsql' => $this->getPostgresIndexes($table),
            default => throw new LogicException('Index inspection is not supported for this driver.'),
        };
    }

    /** @return list<array{name: string, columns: list<string|null>, unique: bool, primary: bool}> */
    private function getPostgresIndexes(string $table): array
    {
        $sql = <<<'SQL'
SELECT index_relation.relname AS name,
       CASE WHEN index_catalog.indisunique THEN 1 ELSE 0 END AS is_unique,
       CASE WHEN index_catalog.indisprimary THEN 1 ELSE 0 END AS is_primary,
       key_column.ordinality AS position,
       attribute.attname AS column_name
FROM pg_class table_relation
JOIN pg_index index_catalog ON index_catalog.indrelid = table_relation.oid
JOIN pg_class index_relation ON index_relation.oid = index_catalog.indexrelid
LEFT JOIN LATERAL unnest(index_catalog.indkey) WITH ORDINALITY AS key_column(attribute_number, ordinality)
    ON key_column.ordinality <= index_catalog.indnkeyatts
LEFT JOIN pg_attribute attribute
    ON attribute.attrelid = table_relation.oid AND attribute.attnum = key_column.attribute_number
WHERE table_relation.oid = to_regclass(?)
ORDER BY index_relation.relname, key_column.ordinality
SQL;

        $quoted = implode('.', array_map(static fn (string $part): string => '"' . str_replace('"', '""', $part) . '"', explode('.', $table, 2)));
        $indexes = [];
        foreach ($this->connection->select($sql, [$quoted]) as $row) {
            $name = (string) $row->name;
            if (!isset($indexes[$name])) {
                $indexes[$name] = [
                    'name' => $name,
                    'columns' => [],
                    'unique' => (int) $row->is_unique === 1,
                    'primary' => (int) $row->is_primary === 1,
                ];
            }
            if ($row->position !== null) {
                $indexes[$name]['columns'][(int) $row->position] = $row->column_name === null
                    ? null
                    : (string) $row->column_name;
            }
        }

        ksort($indexes);
        foreach ($indexes as &$index) {
            ksort($index['columns']);
            $index['columns'] = array_values($index['columns']);
        }
        unset($index);

        return array_values($indexes);
    }

    /** @return list<array{name: string, columns: list<string|null>, unique: bool, primary: bool}> */
    private function getMySqlIndexes(string $table): array
    {
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';
        $indexes = [];
        try {
            $rows = $this->connection->select("SHOW INDEX FROM {$quotedTable}");
        } catch (DatabaseException $exception) {
            $cause = $exception->getPrevious();
            if ($cause instanceof PDOException && (int) ($cause->errorInfo[1] ?? 0) === 1146) {
                return [];
            }
            throw $exception;
        }

        foreach ($rows as $row) {
            $name = (string) $row->Key_name;
            if (!isset($indexes[$name])) {
                $indexes[$name] = [
                    'name' => $name,
                    'columns' => [],
                    'unique' => (int) $row->Non_unique === 0,
                    'primary' => $name === 'PRIMARY',
                ];
            }
            $indexes[$name]['columns'][(int) $row->Seq_in_index] = $row->Column_name === null
                ? null
                : (string) $row->Column_name;
        }

        ksort($indexes);
        foreach ($indexes as &$index) {
            ksort($index['columns']);
            $index['columns'] = array_values($index['columns']);
        }
        unset($index);

        return array_values($indexes);
    }

    /** @return list<array{name: string, columns: list<string|null>, unique: bool, primary: bool}> */
    private function getSqliteIndexes(string $table): array
    {
        $quotedTable = '"' . str_replace('"', '""', $table) . '"';
        $indexes = [];
        $primaryColumns = [];

        foreach ($this->connection->select("PRAGMA table_info({$quotedTable})") as $column) {
            if ((int) $column->pk > 0) {
                $primaryColumns[(int) $column->pk] = (string) $column->name;
            }
        }
        if ($primaryColumns !== []) {
            ksort($primaryColumns);
            $indexes[] = ['name' => 'PRIMARY', 'columns' => array_values($primaryColumns), 'unique' => true, 'primary' => true];
        }

        foreach ($this->connection->select("PRAGMA index_list({$quotedTable})") as $index) {
            if ($index->origin === 'pk') {
                continue;
            }

            $name = (string) $index->name;
            $quotedName = '"' . str_replace('"', '""', $name) . '"';
            $columns = [];
            foreach ($this->connection->select("PRAGMA index_info({$quotedName})") as $column) {
                $columns[(int) $column->seqno] = $column->name === null ? null : (string) $column->name;
            }
            ksort($columns);
            $indexes[] = [
                'name' => $name,
                'columns' => array_values($columns),
                'unique' => (bool) $index->unique,
                'primary' => false,
            ];
        }

        usort($indexes, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $indexes;
    }

    /**
     * The tables in the connection's database (PostgreSQL: its current schema).
     *
     * @return list<string>
     */
    public function getTables(): array
    {
        return $this->firstColumn($this->connection->select($this->grammar->compileGetAllTables()), 'name');
    }

    /**
     * The column names of a table, in order.
     *
     * @return list<string>
     */
    public function getColumns(string $table): array
    {
        $rows = $this->connection->select($this->grammar->compileGetColumns($table));
        $names = $this->firstColumn($rows, 'column_name');

        return $names !== [] ? $names : $this->firstColumn($rows, 'field');
    }

    /**
     * The database type of a column, or null when it does not exist.
     */
    public function getColumnType(string $table, string $column): ?string
    {
        $row = $this->connection->selectOne($this->grammar->compileGetColumnType($table, $column));
        $type = $row === null ? null : array_change_key_case((array) $row)['data_type'] ?? null;

        return is_string($type) ? $type : null;
    }

    /**
     * The values of one column across rows, read case-insensitively.
     *
     * @param array<object> $rows
     * @return list<string>
     */
    private function firstColumn(array $rows, string $key): array
    {
        $values = [];
        foreach ($rows as $row) {
            $value = array_change_key_case((array) $row)[$key] ?? null;
            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Run $statements in one transaction where DDL is transactional.
     *
     * @param array<string> $statements
     */
    private function atomically(array $statements): void
    {
        if (in_array($this->connection->getDriverName(), ['sqlite', 'pgsql'], true) && count($statements) > 1) {
            $this->connection->transaction(fn () => $this->executeStatements($statements));

            return;
        }

        $this->executeStatements($statements);
    }

    /**
     * Execute multiple statements.
     *
     * @param array<string> $statements
     */
    private function executeStatements(array $statements): void
    {
        foreach ($statements as $statement) {
            $this->connection->statement($statement);
        }
    }

    /**
     * Get the grammar instance.
     */
    public function getGrammar(): SchemaGrammarInterface
    {
        return $this->grammar;
    }

    /**
     * Get the connection instance.
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Execute a raw SQL statement.
     */
    public function raw(string $sql): bool
    {
        return $this->connection->statement($sql);
    }

    /**
     * Disable foreign key constraints.
     */
    public function disableForeignKeyConstraints(): void
    {
        $this->connection->statement(
            $this->grammar->compileDisableForeignKeyConstraints()
        );
    }

    /**
     * Enable foreign key constraints.
     */
    public function enableForeignKeyConstraints(): void
    {
        $this->connection->statement(
            $this->grammar->compileEnableForeignKeyConstraints()
        );
    }

    /**
     * Execute callback with foreign key constraints disabled.
     */
    public function withoutForeignKeyConstraints(Closure $callback): mixed
    {
        $this->disableForeignKeyConstraints();
        
        try {
            return $callback($this);
        } finally {
            $this->enableForeignKeyConstraints();
        }
    }
}
