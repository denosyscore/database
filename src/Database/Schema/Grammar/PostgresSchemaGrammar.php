<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Schema\Grammar;

use CFXP\Core\Database\Schema\Blueprint;
use CFXP\Core\Database\Schema\Column;
use CFXP\Core\Database\Schema\IndexType;

/**
 * PostgreSQL-specific schema grammar.
 */
class PostgresSchemaGrammar extends SchemaGrammar
{
    public function getDriverName(): string
    {
        return 'pgsql';
    }

    /**
     * @inheritDoc
     */
    /**
     * @return array<string, mixed>
     */
public function compileCreate(Blueprint $blueprint): array
    {
        $statements = [];
        
        $columns = [];
        
        // Compile columns
        foreach ($blueprint->getColumns() as $column) {
            $columns[] = $this->compileColumn($column);
        }
        
        // Build CREATE TABLE statement
        $sql = $blueprint->isTemporary() ? 'CREATE TEMPORARY TABLE ' : 'CREATE TABLE ';
        $sql .= $this->wrapTable($blueprint->getTable());
        $sql .= " (\n";
        $sql .= '  ' . implode(",\n  ", $columns);
        $sql .= "\n)";
        
        $statements[] = $sql;
        
        // Add indexes as separate statements (PostgreSQL style)
        foreach ($blueprint->getColumns() as $column) {
            if ($column->isPrimary() && !$column->isAutoIncrement()) {
                $statements[] = sprintf(
                    'ALTER TABLE %s ADD PRIMARY KEY (%s)',
                    $this->wrapTable($blueprint->getTable()),
                    $this->wrapColumn($column->getName())
                );
            }
            if ($column->isUnique()) {
                $statements[] = sprintf(
                    'CREATE UNIQUE INDEX %s ON %s (%s)',
                    $this->wrapColumn('uq_' . $column->getName()),
                    $this->wrapTable($blueprint->getTable()),
                    $this->wrapColumn($column->getName())
                );
            }
            if ($column->hasIndex()) {
                $statements[] = sprintf(
                    'CREATE INDEX %s ON %s (%s)',
                    $this->wrapColumn('idx_' . $column->getName()),
                    $this->wrapTable($blueprint->getTable()),
                    $this->wrapColumn($column->getName())
                );
            }
        }
        
        // Compile explicit indexes
        foreach ($blueprint->getIndexes() as $index) {
            $columns = implode(', ', array_map(
                fn($col) => $this->wrapColumn($col),
                $index->getColumns()
            ));
            
            $statements[] = match ($index->getType()) {
                IndexType::Primary => sprintf(
                    'ALTER TABLE %s ADD PRIMARY KEY (%s)',
                    $this->wrapTable($blueprint->getTable()),
                    $columns
                ),
                IndexType::Unique => sprintf(
                    'CREATE UNIQUE INDEX %s ON %s (%s)',
                    $this->wrapColumn($index->getName()),
                    $this->wrapTable($blueprint->getTable()),
                    $columns
                ),
                default => sprintf(
                    'CREATE INDEX %s ON %s (%s)',
                    $this->wrapColumn($index->getName()),
                    $this->wrapTable($blueprint->getTable()),
                    $columns
                ),
            };
        }
        
        // Compile foreign keys
        foreach ($blueprint->getForeignKeys() as $foreignKey) {
            $statements[] = sprintf(
                'ALTER TABLE %s ADD %s',
                $this->wrapTable($blueprint->getTable()),
                $this->compileForeignKey($foreignKey)
            );
        }
        
        // Add table comment if specified
        if ($blueprint->getComment()) {
            $statements[] = sprintf(
                "COMMENT ON TABLE %s IS '%s'",
                $this->wrapTable($blueprint->getTable()),
                $this->escapeString($blueprint->getComment())
            );
        }
        
        return $statements;
    }

    /**
     * @inheritDoc
     */
    public function compileAlter(Blueprint $blueprint): array
    {
        $statements = [];
        
        // Drop foreign keys first
        foreach ($blueprint->getDropForeignKeys() as $name) {
            $statements[] = sprintf(
                'ALTER TABLE %s DROP CONSTRAINT %s',
                $this->wrapTable($blueprint->getTable()),
                $this->wrapColumn($name)
            );
        }
        
        // Drop indexes
        foreach ($blueprint->getDropIndexes() as $name) {
            $statements[] = sprintf('DROP INDEX IF EXISTS %s', $this->wrapColumn($name));
        }
        
        // Drop columns
        foreach ($blueprint->getDropColumns() as $column) {
            $statements[] = sprintf(
                'ALTER TABLE %s DROP COLUMN %s',
                $this->wrapTable($blueprint->getTable()),
                $this->wrapColumn($column)
            );
        }
        
        // Rename columns
        foreach ($blueprint->getRenameColumns() as $from => $to) {
            $statements[] = sprintf(
                'ALTER TABLE %s RENAME COLUMN %s TO %s',
                $this->wrapTable($blueprint->getTable()),
                $this->wrapColumn($from),
                $this->wrapColumn($to)
            );
        }
        
        // Add new columns
        foreach ($blueprint->getColumns() as $column) {
            $statements[] = sprintf(
                'ALTER TABLE %s ADD COLUMN %s',
                $this->wrapTable($blueprint->getTable()),
                $this->compileColumn($column)
            );
        }
        
        // Add indexes
        foreach ($blueprint->getIndexes() as $index) {
            $columns = implode(', ', array_map(
                fn($col) => $this->wrapColumn($col),
                $index->getColumns()
            ));
            
            $statements[] = match ($index->getType()) {
                IndexType::Primary => sprintf(
                    'ALTER TABLE %s ADD PRIMARY KEY (%s)',
                    $this->wrapTable($blueprint->getTable()),
                    $columns
                ),
                IndexType::Unique => sprintf(
                    'CREATE UNIQUE INDEX %s ON %s (%s)',
                    $this->wrapColumn($index->getName()),
                    $this->wrapTable($blueprint->getTable()),
                    $columns
                ),
                default => sprintf(
                    'CREATE INDEX %s ON %s (%s)',
                    $this->wrapColumn($index->getName()),
                    $this->wrapTable($blueprint->getTable()),
                    $columns
                ),
            };
        }
        
        // Add foreign keys
        foreach ($blueprint->getForeignKeys() as $foreignKey) {
            $statements[] = sprintf(
                'ALTER TABLE %s ADD %s',
                $this->wrapTable($blueprint->getTable()),
                $this->compileForeignKey($foreignKey)
            );
        }
        
        return $statements;
    }

    /**
     * @inheritDoc
     */
    public function compileTableExists(string $table): string
    {
        return "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename = '{$this->escapeString($table)}'";
    }

    /**
     * @inheritDoc
     */
    public function compileGetAllTables(): string
    {
        return "SELECT tablename as name FROM pg_tables WHERE schemaname = 'public'";
    }

    /**
     * @inheritDoc
     */
    public function compileGetColumns(string $table): string
    {
        return "SELECT column_name FROM information_schema.columns "
             . "WHERE table_schema = 'public' AND table_name = '{$this->escapeString($table)}'";
    }

    /**
     * @inheritDoc
     */
    public function compileGetColumnType(string $table, string $column): string
    {
        return "SELECT data_type FROM information_schema.columns "
             . "WHERE table_schema = 'public' "
             . "AND table_name = '{$this->escapeString($table)}' "
             . "AND column_name = '{$this->escapeString($column)}'";
    }

    /**
     * @inheritDoc
     */
    public function compileDisableForeignKeyConstraints(): string
    {
        return 'SET CONSTRAINTS ALL DEFERRED';
    }

    /**
     * @inheritDoc
     */
    public function compileEnableForeignKeyConstraints(): string
    {
        return 'SET CONSTRAINTS ALL IMMEDIATE';
    }

    // =========================================================================
    // PostgreSQL-Specific Type Overrides
    // =========================================================================

    protected function wrap(string $value): string
    {
        if ($value === '*' || str_starts_with($value, '"')) {
            return $value;
        }
        return '"' . str_replace('"', '""', $value) . '"';
    }

    protected function getAutoIncrementKeyword(): string
    {
        return ''; // PostgreSQL uses SERIAL types instead
    }

    protected function typeBigInteger(Column $column): string
    {
        if ($column->isAutoIncrement()) {
            return 'BIGSERIAL';
        }
        return 'BIGINT';
    }

    protected function typeInteger(Column $column): string
    {
        if ($column->isAutoIncrement()) {
            return 'SERIAL';
        }
        return 'INTEGER';
    }

    protected function typeSmallInteger(Column $column): string
    {
        if ($column->isAutoIncrement()) {
            return 'SMALLSERIAL';
        }
        return 'SMALLINT';
    }

    protected function typeTinyInteger(Column $column): string
    {
        return 'SMALLINT'; // PostgreSQL doesn't have TINYINT
    }

    protected function typeMediumInteger(Column $column): string
    {
        return 'INTEGER'; // PostgreSQL doesn't have MEDIUMINT
    }

    protected function typeBoolean(Column $column): string
    {
        return 'BOOLEAN';
    }

    protected function typeDateTime(Column $column): string
    {
        return 'TIMESTAMP WITHOUT TIME ZONE';
    }

    protected function typeTimestamp(Column $column): string
    {
        return 'TIMESTAMP WITHOUT TIME ZONE';
    }

    protected function typeMediumText(Column $column): string
    {
        return 'TEXT'; // PostgreSQL TEXT is unlimited
    }

    protected function typeLongText(Column $column): string
    {
        return 'TEXT';
    }

    protected function typeBlob(Column $column): string
    {
        return 'BYTEA';
    }

    protected function typeBinary(Column $column): string
    {
        return 'BYTEA';
    }

    protected function typeUuid(Column $column): string
    {
        return 'UUID';
    }

    protected function typeEnum(Column $column): string
    {
        // PostgreSQL requires creating custom enum types
        // For simplicity, use VARCHAR with CHECK constraint
        $values = array_map(
            fn($v) => "'{$this->escapeString($v)}'",
            $column->getEnumValues()
        );
        return 'VARCHAR(255)'; // Check constraint would be separate
    }

    protected function typeSet(Column $column): string
    {
        return 'VARCHAR(255)[]'; // Use array for SET-like behavior
    }

    protected function typeYear(Column $column): string
    {
        return 'SMALLINT'; // PostgreSQL doesn't have YEAR type
    }
}
