<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Schema\Grammar;

use CFXP\Core\Database\Schema\Blueprint;
use CFXP\Core\Database\Schema\Column;
use CFXP\Core\Database\Schema\IndexType;

/**
 * MySQL-specific schema grammar.
 */
class MySqlSchemaGrammar extends SchemaGrammar
{
    public function getDriverName(): string
    {
        return 'mysql';
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
        $indexes = [];
        $foreignKeys = [];
        
        // Compile columns
        foreach ($blueprint->getColumns() as $column) {
            $columns[] = $this->compileColumn($column);
            
            // Handle column-level constraints
            // For MySQL, auto-increment columns MUST be a key
            if ($column->isPrimary()) {
                $indexes[] = "PRIMARY KEY ({$this->wrapColumn($column->getName())})";
            } elseif ($column->isUnique()) {
                $indexes[] = "UNIQUE KEY {$this->wrapColumn('uq_' . $column->getName())} ({$this->wrapColumn($column->getName())})";
            } elseif ($column->hasIndex()) {
                $indexes[] = "KEY {$this->wrapColumn('idx_' . $column->getName())} ({$this->wrapColumn($column->getName())})";
            }
        }
        
        // Compile explicit indexes
        foreach ($blueprint->getIndexes() as $index) {
            $indexes[] = $this->compileIndex($index);
        }
        
        // Compile foreign keys
        foreach ($blueprint->getForeignKeys() as $foreignKey) {
            $foreignKeys[] = $this->compileForeignKey($foreignKey);
        }
        
        // Build CREATE TABLE statement
        $sql = $blueprint->isTemporary() ? 'CREATE TEMPORARY TABLE ' : 'CREATE TABLE ';
        $sql .= $this->wrapTable($blueprint->getTable());
        $sql .= " (\n";
        
        $definitions = array_merge($columns, $indexes, $foreignKeys);
        $sql .= '  ' . implode(",\n  ", $definitions);
        
        $sql .= "\n)";
        
        // Table options
        if ($blueprint->getEngine()) {
            $sql .= " ENGINE={$blueprint->getEngine()}";
        }
        
        if ($blueprint->getCharset()) {
            $sql .= " DEFAULT CHARSET={$blueprint->getCharset()}";
        }
        
        if ($blueprint->getCollation()) {
            $sql .= " COLLATE={$blueprint->getCollation()}";
        }
        
        if ($blueprint->getComment()) {
            $sql .= " COMMENT='{$this->escapeString($blueprint->getComment())}'";
        }
        
        $statements[] = $sql;
        
        return $statements;
    }

    /**
     * @inheritDoc
     */
    public function compileAlter(Blueprint $blueprint): array
    {
        $statements = [];
        
        // Drop foreign keys first (to allow column drops)
        foreach ($blueprint->getDropForeignKeys() as $name) {
            $statements[] = "ALTER TABLE {$this->wrapTable($blueprint->getTable())} "
                          . "DROP FOREIGN KEY {$this->wrapColumn($name)}";
        }
        
        // Drop indexes
        foreach ($blueprint->getDropIndexes() as $name) {
            $statements[] = "ALTER TABLE {$this->wrapTable($blueprint->getTable())} "
                          . "DROP INDEX {$this->wrapColumn($name)}";
        }
        
        // Drop columns
        if ($dropColumns = $blueprint->getDropColumns()) {
            $drops = array_map(
                fn($col) => "DROP COLUMN {$this->wrapColumn($col)}",
                $dropColumns
            );
            $statements[] = "ALTER TABLE {$this->wrapTable($blueprint->getTable())} "
                          . implode(', ', $drops);
        }
        
        // Rename columns
        foreach ($blueprint->getRenameColumns() as $from => $to) {
            $statements[] = "ALTER TABLE {$this->wrapTable($blueprint->getTable())} "
                          . "RENAME COLUMN {$this->wrapColumn($from)} TO {$this->wrapColumn($to)}";
        }
        
        // Add new columns or modify existing columns
        foreach ($blueprint->getColumns() as $column) {
            if ($column->isChange()) {
                // Modify existing column
                $sql = "ALTER TABLE {$this->wrapTable($blueprint->getTable())} "
                     . "MODIFY COLUMN " . $this->compileColumn($column);
            } else {
                // Add new column
                $sql = "ALTER TABLE {$this->wrapTable($blueprint->getTable())} "
                     . "ADD COLUMN " . $this->compileColumn($column);
            }
            
            if ($column->getAfter()) {
                $sql .= " AFTER {$this->wrapColumn($column->getAfter())}";
            }
            
            $statements[] = $sql;
        }
        
        // Add indexes
        foreach ($blueprint->getIndexes() as $index) {
            $columns = implode(', ', array_map(
                fn($col) => $this->wrapColumn($col),
                $index->getColumns()
            ));
            
            $sql = "ALTER TABLE {$this->wrapTable($blueprint->getTable())} ADD ";
            $sql .= match ($index->getType()) {
                IndexType::Primary => "PRIMARY KEY ({$columns})",
                IndexType::Unique => "UNIQUE KEY {$this->wrapColumn($index->getName())} ({$columns})",
                IndexType::Index => "KEY {$this->wrapColumn($index->getName())} ({$columns})",
                IndexType::Fulltext => "FULLTEXT KEY {$this->wrapColumn($index->getName())} ({$columns})",
                IndexType::Spatial => "SPATIAL KEY {$this->wrapColumn($index->getName())} ({$columns})",
            };
            
            $statements[] = $sql;
        }
        
        // Add foreign keys
        foreach ($blueprint->getForeignKeys() as $foreignKey) {
            $statements[] = "ALTER TABLE {$this->wrapTable($blueprint->getTable())} "
                          . "ADD " . $this->compileForeignKey($foreignKey);
        }
        
        return $statements;
    }

    /**
     * @inheritDoc
     */
    public function compileTableExists(string $table): string
    {
        return "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES "
             . "WHERE TABLE_SCHEMA = DATABASE() "
             . "AND TABLE_NAME = '{$this->escapeString($table)}'";
    }

    /**
     * @inheritDoc
     */
    public function compileGetAllTables(): string
    {
        return "SELECT TABLE_NAME as name FROM INFORMATION_SCHEMA.TABLES "
             . "WHERE TABLE_SCHEMA = DATABASE() "
             . "AND TABLE_TYPE = 'BASE TABLE'";
    }

    /**
     * @inheritDoc
     */
    public function compileGetColumns(string $table): string
    {
        return "SHOW COLUMNS FROM {$this->wrapTable($table)}";
    }

    /**
     * @inheritDoc
     */
    public function compileGetColumnType(string $table, string $column): string
    {
        return "SELECT DATA_TYPE, COLUMN_TYPE "
             . "FROM INFORMATION_SCHEMA.COLUMNS "
             . "WHERE TABLE_SCHEMA = DATABASE() "
             . "AND TABLE_NAME = '{$this->escapeString($table)}' "
             . "AND COLUMN_NAME = '{$this->escapeString($column)}'";
    }

    /**
     * @inheritDoc
     */
    public function compileDisableForeignKeyConstraints(): string
    {
        return 'SET FOREIGN_KEY_CHECKS = 0';
    }

    /**
     * @inheritDoc
     */
    public function compileEnableForeignKeyConstraints(): string
    {
        return 'SET FOREIGN_KEY_CHECKS = 1';
    }

    // =========================================================================
    // MySQL-Specific Type Overrides
    // =========================================================================

    protected function typeBoolean(Column $column): string
    {
        return 'TINYINT(1)';
    }

    protected function typeUuid(Column $column): string
    {
        return 'CHAR(36)';
    }

    protected function typeJson(Column $column): string
    {
        return 'JSON';
    }
}
