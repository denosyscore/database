<?php

declare(strict_types=1);

namespace Denosys\Database\Schema;

use Closure;
use Denosys\Database\Connection\Connection;
use Denosys\Database\Schema\Grammar\SchemaGrammarInterface;

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
        
        $statements = $this->grammar->compileCreate($blueprint);
        $this->executeStatements($statements);
    }

    /**
     * Modify an existing table.
     */
    public function table(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        
        $statements = $this->grammar->compileAlter($blueprint);
        $this->executeStatements($statements);
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
    public function dropAllTables(): void
    {
        $tables = $this->getTables();
        
        // Disable foreign key checks for clean drop
        $this->connection->statement($this->grammar->compileDisableForeignKeyConstraints());
        
        foreach ($tables as $table) {
            $this->drop($table);
        }
        
        $this->connection->statement($this->grammar->compileEnableForeignKeyConstraints());
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
     * Get all tables in the database.
     * 
     * @return string[]
     */
    /**
     * @return array<string, mixed>
     */
public function getTables(): array
    {
        $sql = $this->grammar->compileGetAllTables();
        $results = $this->connection->select($sql);
        
        return array_map(fn($row) => ((array) $row)['name'], $results);
    }

    /**
     * Get all columns in a table.
     * 
     * @return string[]
     */
    public function getColumns(string $table): array
    {
        $sql = $this->grammar->compileGetColumns($table);
        $results = $this->connection->select($sql);
        
        return array_map(
            fn($row) => ((array) $row)['column_name'] ?? ((array) $row)['Field'] ?? '',
            $results
        );
    }

    /**
     * Get the column type for a column.
     */
    public function getColumnType(string $table, string $column): ?string
    {
        $sql = $this->grammar->compileGetColumnType($table, $column);
        $result = $this->connection->selectOne($sql);
        
        return $result ? ((array) $result)['data_type'] ?? ((array) $result)['Type'] ?? null : null;
    }

    /**
     * Execute multiple statements.
      * @param array<string, mixed> $statements
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
