<?php

declare(strict_types=1);

namespace Denosys\Database\Schema\Grammar;

use Denosys\Database\Schema\Blueprint;
use Denosys\Database\Schema\Column;
use Denosys\Database\Schema\IndexDefinition;
use Denosys\Database\Schema\IndexType;

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
    /**
     * PostgreSQL has no UNSIGNED modifier.
     */
    protected function supportsUnsignedColumns(): bool
    {
        return false;
    }

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
            // SERIAL/BIGSERIAL only creates a sequence; the primary key must be declared explicitly.
            if ($column->isPrimary()) {
                $statements[] = sprintf(
                    'ALTER TABLE %s ADD PRIMARY KEY (%s)',
                    $this->wrapTable($blueprint->getTable()),
                    $this->wrapColumn($column->getName())
                );
            }
            if ($column->isUnique()) {
                $statements[] = sprintf(
                    'CREATE UNIQUE INDEX %s ON %s (%s)',
                    $this->wrapColumn(IndexDefinition::defaultName(IndexType::Unique, $blueprint->getTable(), [$column->getName()])),
                    $this->wrapTable($blueprint->getTable()),
                    $this->wrapColumn($column->getName())
                );
            }
            if ($column->hasIndex()) {
                $statements[] = sprintf(
                    'CREATE INDEX %s ON %s (%s)',
                    $this->wrapColumn(IndexDefinition::defaultName(IndexType::Index, $blueprint->getTable(), [$column->getName()])),
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
        
        // Drop indexes (an index lives in its table's schema)
        foreach ($blueprint->getDropIndexes() as $name) {
            $statements[] = sprintf('DROP INDEX IF EXISTS %s', $this->wrapIndex($blueprint->getTable(), $name));
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
        
        // Add new columns, or change existing ones
        foreach ($blueprint->getColumns() as $column) {
            if ($column->isChange()) {
                $statements = [...$statements, ...$this->compileChange($blueprint->getTable(), $column)];
                continue;
            }
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
     * Change a column in place: type (converting existing values), then
     * nullability and default.
     *
     * @return list<string>
     */
    protected function compileChange(string $table, Column $column): array
    {
        $alter = 'ALTER TABLE ' . $this->wrapTable($table) . ' ALTER COLUMN ' . $this->wrapColumn($column->getName());
        // SERIAL names exist only for CREATE TABLE: an auto-incrementing column
        // changes to its storage type and keeps its sequence default.
        $type = match ($type = $this->getTypeDefinition($column)) {
            'BIGSERIAL' => 'BIGINT',
            'SERIAL' => 'INTEGER',
            'SMALLSERIAL' => 'SMALLINT',
            default => $type,
        };

        // A string target uses assignment conversion, which refuses a value too
        // long for the new length; an explicit cast would truncate it.
        $statements = [
            preg_match('/^(VARCHAR|CHAR|TEXT)\b/', $type) === 1
                ? sprintf('%s TYPE %s', $alter, $type)
                : sprintf('%s TYPE %s USING %s::%s', $alter, $type, $this->wrapColumn($column->getName()), $type),
            $alter . ($column->isNullable() ? ' DROP NOT NULL' : ' SET NOT NULL'),
        ];
        if ($column->hasDefaultValue()) {
            $statements[] = $alter . ' SET DEFAULT ' . $this->compileDefaultValue($column->getDefault());
        } elseif (!$column->isAutoIncrement()) {
            $statements[] = $alter . ' DROP DEFAULT';
        }

        return $statements;
    }

    /**
     * An index name, qualified with the schema of a qualified table.
     */
    protected function wrapIndex(string $table, string $name): string
    {
        return str_contains($table, '.')
            ? $this->wrap(explode('.', $table, 2)[0]) . '.' . $this->wrap($name)
            : $this->wrap($name);
    }

    /**
     * The schema and table to inspect. An unqualified table is looked up in
     * the connection's current schema (the first on its search path).
     *
     * @return array{string, string} SQL for the schema, and the escaped table name
     */
    protected function locate(string $table): array
    {
        if (str_contains($table, '.')) {
            [$schema, $table] = explode('.', $table, 2);

            return ["'" . $this->escapeString($schema) . "'", $this->escapeString($table)];
        }

        return ['current_schema()', $this->escapeString($table)];
    }

    /**
     * @inheritDoc
     */
    public function compileTableExists(string $table): string
    {
        [$schema, $name] = $this->locate($table);

        return "SELECT tablename FROM pg_tables WHERE schemaname = {$schema} AND tablename = '{$name}'";
    }

    /**
     * @inheritDoc
     */
    public function compileGetAllTables(): string
    {
        return 'SELECT tablename AS name FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename';
    }

    /**
     * @inheritDoc
     */
    public function compileGetColumns(string $table): string
    {
        [$schema, $name] = $this->locate($table);

        return 'SELECT column_name FROM information_schema.columns '
             . "WHERE table_schema = {$schema} AND table_name = '{$name}' ORDER BY ordinal_position";
    }

    /**
     * @inheritDoc
     */
    public function compileGetColumnType(string $table, string $column): string
    {
        [$schema, $name] = $this->locate($table);

        return 'SELECT data_type FROM information_schema.columns '
             . "WHERE table_schema = {$schema} AND table_name = '{$name}' "
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
