<?php

declare(strict_types=1);

namespace Denosys\Database\Schema\Grammar;

use Denosys\Database\Schema\Blueprint;
use Denosys\Database\Schema\Column;
use Denosys\Database\Schema\ForeignKeyDefinition;
use Denosys\Database\Schema\IndexDefinition;
use Denosys\Database\Schema\IndexType;

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
        
        // SQLite must declare non-incrementing column primary keys at table level.
        $explicitPrimaryKeys = [];
        foreach ($blueprint->getIndexes() as $index) {
            if ($index->getType() === IndexType::Primary) {
                $explicitPrimaryKeys = $index->getColumns();
                break;
            }
        }
        
        if ($explicitPrimaryKeys === []) {
            foreach ($blueprint->getColumns() as $column) {
                if ($column->isPrimary() && !$column->isAutoIncrement()) {
                    $explicitPrimaryKeys[] = $column->getName();
                }
            }
        }

        if ($explicitPrimaryKeys !== [] && $primaryKey === null) {
            $columns[] = 'PRIMARY KEY (' . implode(', ', array_map(
                fn($col) => $this->wrapColumn($col),
                $explicitPrimaryKeys
            )) . ')';
        }

        foreach ($blueprint->getForeignKeys() as $foreignKey) {
            $columns[] = $this->compileForeignKey($foreignKey);
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
        if (self::needsRebuild($blueprint)) {
            throw new \LogicException(
                'SQLite cannot change a column or add or drop a foreign key with ALTER TABLE; '
                . 'SchemaBuilder::table() rebuilds the table for these changes.'
            );
        }

        $statements = [];

        // Indexes first: SQLite refuses to drop a column an index still uses.
        foreach ($blueprint->getDropIndexes() as $name) {
            $statements[] = sprintf('DROP INDEX IF EXISTS %s', $this->wrapColumn($name));
        }

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
     * Whether $blueprint needs the table rebuilt: SQLite's ALTER TABLE cannot
     * change a column or add or drop a foreign key.
     */
    public static function needsRebuild(Blueprint $blueprint): bool
    {
        foreach ($blueprint->getColumns() as $column) {
            if ($column->isChange()) {
                return true;
            }
        }

        return $blueprint->getForeignKeys() !== [] || $blueprint->getDropForeignKeys() !== [];
    }

    /**
     * The definition of one column, for a table rebuild.
     */
    public function columnDefinition(Column $column): string
    {
        return $this->compileColumn($column);
    }

    /**
     * The definition of one foreign key, for a table rebuild.
     */
    public function foreignKeyDefinition(ForeignKeyDefinition $foreignKey): string
    {
        return $this->compileForeignKey($foreignKey);
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
        return "SELECT name AS column_name FROM pragma_table_info('{$this->escapeString($table)}')";
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
