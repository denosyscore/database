<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Schema\Grammar;

use CFXP\Core\Database\Schema\Blueprint;
use CFXP\Core\Database\Schema\Column;
use CFXP\Core\Database\Schema\IndexType;

/**
 * SQLite-specific schema grammar.
 */
class SqliteSchemaGrammar extends SchemaGrammar
{
    public function getDriverName(): string
    {
        return 'sqlite';
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
        $primaryKey = null;
        
        // Compile columns
        foreach ($blueprint->getColumns() as $column) {
            $columnDef = $this->compileColumn($column);
            
            // SQLite handles PRIMARY KEY inline for auto-increment
            if ($column->isPrimary() && $column->isAutoIncrement()) {
                $primaryKey = $column->getName();
            }
            
            $columns[] = $columnDef;
        }
        
        // Add composite primary key if not inline
        $explicitPrimaryKeys = [];
        foreach ($blueprint->getIndexes() as $index) {
            if ($index->getType() === IndexType::Primary) {
                $explicitPrimaryKeys = $index->getColumns();
                break;
            }
        }
        
        if (!empty($explicitPrimaryKeys) && $primaryKey === null) {
            $columns[] = 'PRIMARY KEY (' . implode(', ', array_map(
                fn($col) => $this->wrapColumn($col),
                $explicitPrimaryKeys
            )) . ')';
        }
        
        // Build CREATE TABLE statement
        $sql = $blueprint->isTemporary() ? 'CREATE TEMPORARY TABLE ' : 'CREATE TABLE ';
        $sql .= $this->wrapTable($blueprint->getTable());
        $sql .= " (\n";
        $sql .= '  ' . implode(",\n  ", $columns);
        $sql .= "\n)";
        
        $statements[] = $sql;
        
        // Create indexes as separate statements
        foreach ($blueprint->getColumns() as $column) {
            if ($column->isUnique() && !$column->isPrimary()) {
                $statements[] = sprintf(
                    'CREATE UNIQUE INDEX %s ON %s (%s)',
                    $this->wrapColumn('uq_' . $blueprint->getTable() . '_' . $column->getName()),
                    $this->wrapTable($blueprint->getTable()),
                    $this->wrapColumn($column->getName())
                );
            }
            if ($column->hasIndex()) {
                $statements[] = sprintf(
                    'CREATE INDEX %s ON %s (%s)',
                    $this->wrapColumn('idx_' . $blueprint->getTable() . '_' . $column->getName()),
                    $this->wrapTable($blueprint->getTable()),
                    $this->wrapColumn($column->getName())
                );
            }
        }
        
        foreach ($blueprint->getIndexes() as $index) {
            if ($index->getType() === IndexType::Primary) {
                continue; // Already handled
            }
            
            $columns = implode(', ', array_map(
                fn($col) => $this->wrapColumn($col),
                $index->getColumns()
            ));
            
            $statements[] = match ($index->getType()) {
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
        
        return $statements;
    }

    /**
     * @inheritDoc
     * 
     * Note: SQLite has limited ALTER TABLE support.
     * For complex modifications, table recreation may be needed.
     */
    public function compileAlter(Blueprint $blueprint): array
    {
        $statements = [];
        
        // SQLite doesn't support DROP COLUMN before version 3.35.0
        // We'll generate the statements anyway
        foreach ($blueprint->getDropColumns() as $column) {
            $statements[] = sprintf(
                'ALTER TABLE %s DROP COLUMN %s',
                $this->wrapTable($blueprint->getTable()),
                $this->wrapColumn($column)
            );
        }
        
        // Rename columns (supported in SQLite 3.25.0+)
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
            // SQLite doesn't support MODIFY COLUMN
            if ($column->isChange()) {
                throw new \RuntimeException(
                    'SQLite does not support modifying columns. ' .
                    'Use table recreation or raw SQL instead.'
                );
            }
            
            $statements[] = sprintf(
                'ALTER TABLE %s ADD COLUMN %s',
                $this->wrapTable($blueprint->getTable()),
                $this->compileColumn($column)
            );
        }
        
        // Drop indexes
        foreach ($blueprint->getDropIndexes() as $name) {
            $statements[] = sprintf('DROP INDEX IF EXISTS %s', $this->wrapColumn($name));
        }
        
        // Add indexes
        foreach ($blueprint->getIndexes() as $index) {
            $columns = implode(', ', array_map(
                fn($col) => $this->wrapColumn($col),
                $index->getColumns()
            ));
            
            $statements[] = match ($index->getType()) {
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
        
        return $statements;
    }

    /**
     * @inheritDoc
     */
    public function compileTableExists(string $table): string
    {
        return "SELECT name FROM sqlite_master WHERE type='table' AND name='{$this->escapeString($table)}'";
    }

    /**
     * @inheritDoc
     */
    public function compileGetAllTables(): string
    {
        return "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'";
    }

    /**
     * @inheritDoc
     */
    public function compileGetColumns(string $table): string
    {
        return "PRAGMA table_info({$this->wrapTable($table)})";
    }

    /**
     * @inheritDoc
     */
    public function compileGetColumnType(string $table, string $column): string
    {
        return "SELECT type as data_type FROM pragma_table_info('{$this->escapeString($table)}') WHERE name = '{$this->escapeString($column)}'";
    }

    /**
     * @inheritDoc
     */
    public function compileDisableForeignKeyConstraints(): string
    {
        return 'PRAGMA foreign_keys = OFF';
    }

    /**
     * @inheritDoc
     */
    public function compileEnableForeignKeyConstraints(): string
    {
        return 'PRAGMA foreign_keys = ON';
    }

    // =========================================================================
    // SQLite-Specific Type Overrides
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
        return 'PRIMARY KEY AUTOINCREMENT';
    }

    /**
     * Override column compilation for SQLite-specific PRIMARY KEY handling.
     */
    protected function compileColumn(Column $column): string
    {
        $sql = $this->wrapColumn($column->getName());
        $sql .= ' ' . $this->getTypeDefinition($column);
        
        // SQLite: INTEGER PRIMARY KEY AUTOINCREMENT is special
        if ($column->isAutoIncrement() && $column->isPrimary()) {
            $sql .= ' PRIMARY KEY AUTOINCREMENT';
        } else {
            if (!$column->isNullable()) {
                $sql .= ' NOT NULL';
            }
            
            if ($column->hasDefaultValue()) {
                $sql .= ' DEFAULT ' . $this->compileDefaultValue($column->getDefault());
            }
            
            if ($column->isUnique() && $column->isPrimary()) {
                $sql .= ' PRIMARY KEY';
            }
        }
        
        return $sql;
    }

    protected function typeBigInteger(Column $column): string
    {
        return 'INTEGER'; // SQLite uses INTEGER for all int types
    }

    protected function typeInteger(Column $column): string
    {
        return 'INTEGER';
    }

    protected function typeSmallInteger(Column $column): string
    {
        return 'INTEGER';
    }

    protected function typeTinyInteger(Column $column): string
    {
        return 'INTEGER';
    }

    protected function typeMediumInteger(Column $column): string
    {
        return 'INTEGER';
    }

    protected function typeFloat(Column $column): string
    {
        return 'REAL';
    }

    protected function typeDouble(Column $column): string
    {
        return 'REAL';
    }

    protected function typeDecimal(Column $column): string
    {
        return 'NUMERIC';
    }

    protected function typeBoolean(Column $column): string
    {
        return 'INTEGER'; // SQLite uses 0/1 for boolean
    }

    protected function typeDateTime(Column $column): string
    {
        return 'DATETIME';
    }

    protected function typeTimestamp(Column $column): string
    {
        return 'DATETIME';
    }

    protected function typeDate(Column $column): string
    {
        return 'DATE';
    }

    protected function typeTime(Column $column): string
    {
        return 'TIME';
    }

    protected function typeBinary(Column $column): string
    {
        return 'BLOB';
    }

    protected function typeBlob(Column $column): string
    {
        return 'BLOB';
    }

    protected function typeJson(Column $column): string
    {
        return 'TEXT'; // SQLite stores JSON as TEXT
    }

    protected function typeUuid(Column $column): string
    {
        return 'TEXT'; // SQLite stores UUID as TEXT
    }

    protected function typeEnum(Column $column): string
    {
        return 'TEXT'; // SQLite doesn't have ENUM
    }

    protected function typeSet(Column $column): string
    {
        return 'TEXT';
    }

    protected function typeYear(Column $column): string
    {
        return 'INTEGER';
    }

    protected function typeMediumText(Column $column): string
    {
        return 'TEXT';
    }

    protected function typeLongText(Column $column): string
    {
        return 'TEXT';
    }
}
