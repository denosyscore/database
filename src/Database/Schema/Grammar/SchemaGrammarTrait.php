<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Schema\Grammar;

use CFXP\Core\Database\Schema\Column;
use CFXP\Core\Database\Schema\ColumnType;
use CFXP\Core\Database\Schema\IndexDefinition;
use CFXP\Core\Database\Schema\IndexType;
use CFXP\Core\Database\Schema\ForeignKeyDefinition;

/**
 * Trait providing common schema grammar functionality.
 * 
 * This trait contains shared logic for all grammar implementations.
 * Use this with SchemaGrammarInterface to create grammar classes
 * via composition rather than inheritance.
 */
trait SchemaGrammarTrait
{
    /**
     * Compile a DROP TABLE statement.
     */
    public function compileDrop(string $table): string
    {
        return "DROP TABLE {$this->wrapTable($table)}";
    }

    /**
     * Compile a DROP TABLE IF EXISTS statement.
     */
    public function compileDropIfExists(string $table): string
    {
        return "DROP TABLE IF EXISTS {$this->wrapTable($table)}";
    }

    /**
     * Compile a RENAME TABLE statement.
     */
    public function compileRename(string $from, string $to): string
    {
        return "ALTER TABLE {$this->wrapTable($from)} RENAME TO {$this->wrapTable($to)}";
    }

    // =========================================================================
    // Column Compilation
    // =========================================================================

    /**
     * Compile a column definition.
     */
    protected function compileColumn(Column $column): string
    {
        $sql = $this->wrapColumn($column->getName());
        $sql .= ' ' . $this->getTypeDefinition($column);
        
        if ($column->isUnsigned() && $column->getType()->supportsUnsigned()) {
            $sql .= ' UNSIGNED';
        }
        
        if ($column->getCharset()) {
            $sql .= " CHARACTER SET {$column->getCharset()}";
        }
        
        if ($column->getCollation()) {
            $sql .= " COLLATE {$column->getCollation()}";
        }
        
        if (!$column->isNullable()) {
            $sql .= ' NOT NULL';
        } else {
            $sql .= ' NULL';
        }
        
        if ($column->hasDefaultValue()) {
            $sql .= ' DEFAULT ' . $this->compileDefaultValue($column->getDefault());
        }
        
        if ($column->isAutoIncrement()) {
            $sql .= ' ' . $this->getAutoIncrementKeyword();
        }
        
        if ($column->getComment()) {
            $sql .= " COMMENT '{$this->escapeString($column->getComment())}'";
        }
        
        return $sql;
    }

    /**
     * Get the type definition for a column.
     */
    protected function getTypeDefinition(Column $column): string
    {
        $type = $column->getType();
        
        return match ($type) {
            ColumnType::Id => $this->typeBigInteger($column),
            ColumnType::BigInteger => $this->typeBigInteger($column),
            ColumnType::Integer => $this->typeInteger($column),
            ColumnType::SmallInteger => $this->typeSmallInteger($column),
            ColumnType::TinyInteger => $this->typeTinyInteger($column),
            ColumnType::MediumInteger => $this->typeMediumInteger($column),
            ColumnType::Float => $this->typeFloat($column),
            ColumnType::Double => $this->typeDouble($column),
            ColumnType::Decimal => $this->typeDecimal($column),
            ColumnType::String => $this->typeString($column),
            ColumnType::Char => $this->typeChar($column),
            ColumnType::Text => $this->typeText($column),
            ColumnType::MediumText => $this->typeMediumText($column),
            ColumnType::LongText => $this->typeLongText($column),
            ColumnType::Boolean => $this->typeBoolean($column),
            ColumnType::Date => $this->typeDate($column),
            ColumnType::DateTime => $this->typeDateTime($column),
            ColumnType::Timestamp => $this->typeTimestamp($column),
            ColumnType::Time => $this->typeTime($column),
            ColumnType::Year => $this->typeYear($column),
            ColumnType::Binary => $this->typeBinary($column),
            ColumnType::Blob => $this->typeBlob($column),
            ColumnType::Json => $this->typeJson($column),
            ColumnType::Uuid => $this->typeUuid($column),
            ColumnType::Enum => $this->typeEnum($column),
            ColumnType::Set => $this->typeSet($column),
        };
    }

    // =========================================================================
    // Type Methods (Override in using class for driver-specific types)
    // =========================================================================

    protected function typeBigInteger(Column $column): string
    {
        return 'BIGINT';
    }

    protected function typeInteger(Column $column): string
    {
        return 'INT';
    }

    protected function typeSmallInteger(Column $column): string
    {
        return 'SMALLINT';
    }

    protected function typeTinyInteger(Column $column): string
    {
        return 'TINYINT';
    }

    protected function typeMediumInteger(Column $column): string
    {
        return 'MEDIUMINT';
    }

    protected function typeFloat(Column $column): string
    {
        return 'FLOAT';
    }

    protected function typeDouble(Column $column): string
    {
        return 'DOUBLE';
    }

    protected function typeDecimal(Column $column): string
    {
        $precision = $column->getPrecision() ?? 8;
        $scale = $column->getScale() ?? 2;
        return "DECIMAL({$precision}, {$scale})";
    }

    protected function typeString(Column $column): string
    {
        $length = $column->getLength() ?? 255;
        return "VARCHAR({$length})";
    }

    protected function typeChar(Column $column): string
    {
        $length = $column->getLength() ?? 1;
        return "CHAR({$length})";
    }

    protected function typeText(Column $column): string
    {
        return 'TEXT';
    }

    protected function typeMediumText(Column $column): string
    {
        return 'MEDIUMTEXT';
    }

    protected function typeLongText(Column $column): string
    {
        return 'LONGTEXT';
    }

    protected function typeBoolean(Column $column): string
    {
        return 'BOOLEAN';
    }

    protected function typeDate(Column $column): string
    {
        return 'DATE';
    }

    protected function typeDateTime(Column $column): string
    {
        return 'DATETIME';
    }

    protected function typeTimestamp(Column $column): string
    {
        return 'TIMESTAMP';
    }

    protected function typeTime(Column $column): string
    {
        return 'TIME';
    }

    protected function typeYear(Column $column): string
    {
        return 'YEAR';
    }

    protected function typeBinary(Column $column): string
    {
        $length = $column->getLength() ?? 255;
        return "BINARY({$length})";
    }

    protected function typeBlob(Column $column): string
    {
        return 'BLOB';
    }

    protected function typeJson(Column $column): string
    {
        return 'JSON';
    }

    protected function typeUuid(Column $column): string
    {
        return 'CHAR(36)';
    }

    protected function typeEnum(Column $column): string
    {
        $values = array_map(
            fn($v) => "'{$this->escapeString($v)}'",
            $column->getEnumValues()
        );
        return 'ENUM(' . implode(', ', $values) . ')';
    }

    protected function typeSet(Column $column): string
    {
        $values = array_map(
            fn($v) => "'{$this->escapeString($v)}'",
            $column->getEnumValues()
        );
        return 'SET(' . implode(', ', $values) . ')';
    }

    // =========================================================================
    // Index Compilation
    // =========================================================================

    /**
     * Compile an index definition.
     */
    protected function compileIndex(IndexDefinition $index): string
    {
        $columns = implode(', ', array_map(
            fn($col) => $this->wrapColumn($col),
            $index->getColumns()
        ));

        return match ($index->getType()) {
            IndexType::Primary => "PRIMARY KEY ({$columns})",
            IndexType::Unique => "UNIQUE KEY {$this->wrapColumn($index->getName())} ({$columns})",
            IndexType::Index => "KEY {$this->wrapColumn($index->getName())} ({$columns})",
            IndexType::Fulltext => "FULLTEXT KEY {$this->wrapColumn($index->getName())} ({$columns})",
            IndexType::Spatial => "SPATIAL KEY {$this->wrapColumn($index->getName())} ({$columns})",
        };
    }

    // =========================================================================
    // Foreign Key Compilation
    // =========================================================================

    /**
     * Compile a foreign key definition.
     */
    protected function compileForeignKey(ForeignKeyDefinition $foreignKey): string
    {
        $sql = "CONSTRAINT {$this->wrapColumn($foreignKey->getName())} ";
        $sql .= "FOREIGN KEY ({$this->wrapColumn($foreignKey->getColumn())}) ";
        $sql .= "REFERENCES {$this->wrapTable($foreignKey->getReferencesTable())} ";
        $sql .= "({$this->wrapColumn($foreignKey->getReferencesColumn())})";
        
        if ($foreignKey->getOnDelete()) {
            $sql .= " ON DELETE {$foreignKey->getOnDelete()}";
        }
        
        if ($foreignKey->getOnUpdate()) {
            $sql .= " ON UPDATE {$foreignKey->getOnUpdate()}";
        }
        
        return $sql;
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Get the auto-increment keyword for this driver.
     */
    protected function getAutoIncrementKeyword(): string
    {
        return 'AUTO_INCREMENT';
    }

    /**
     * Wrap a table name.
     */
    protected function wrapTable(string $table): string
    {
        return $this->wrap($table);
    }

    /**
     * Wrap a column name.
     */
    protected function wrapColumn(string $column): string
    {
        return $this->wrap($column);
    }

    /**
     * Wrap a value in keyword identifiers (default: backticks for MySQL).
     */
    protected function wrap(string $value): string
    {
        if ($value === '*' || str_starts_with($value, '`')) {
            return $value;
        }
        
        return '`' . str_replace('`', '``', $value) . '`';
    }

    /**
     * Compile a default value.
     */
    protected function compileDefaultValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        
        if ($value instanceof \DateTimeInterface) {
            return "'{$value->format('Y-m-d H:i:s')}'";
        }
        
        // Check for known SQL expressions - these should not be quoted
        $sqlExpressions = [
            'CURRENT_TIMESTAMP',
            'CURRENT_DATE',
            'CURRENT_TIME',
            'NOW()',
            'NULL',
            'TRUE',
            'FALSE',
        ];
        
        if (is_string($value) && in_array(strtoupper($value), $sqlExpressions, true)) {
            return strtoupper($value);
        }
        
        return "'{$this->escapeString((string) $value)}'";
    }

    /**
     * Escape a string for SQL.
     */
    protected function escapeString(string $value): string
    {
        return addslashes($value);
    }
}
