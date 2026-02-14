<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Schema;

/**
 * Strongly-typed column type definitions.
 * 
 * Using an enum ensures type safety and IDE autocompletion,
 * eliminating magic strings for column types.
 */
enum ColumnType: string
{
    // Integer types
    case TinyInteger = 'tinyInteger';
    case SmallInteger = 'smallInteger';
    case MediumInteger = 'mediumInteger';
    case Integer = 'integer';
    case BigInteger = 'bigInteger';
    
    // Special integer (auto-increment primary key)
    case Id = 'id';
    
    // Floating point types
    case Float = 'float';
    case Double = 'double';
    case Decimal = 'decimal';
    
    // String types
    case Char = 'char';
    case String = 'string';
    case Text = 'text';
    case MediumText = 'mediumText';
    case LongText = 'longText';
    
    // Binary types
    case Binary = 'binary';
    case Blob = 'blob';
    
    // Date and time types
    case Date = 'date';
    case Time = 'time';
    case DateTime = 'dateTime';
    case Timestamp = 'timestamp';
    case Year = 'year';
    
    // Boolean
    case Boolean = 'boolean';
    
    // JSON
    case Json = 'json';
    
    // UUID
    case Uuid = 'uuid';
    
    // Enum (for specific value sets)
    case Enum = 'enum';
    
    // Set (MySQL-specific)
    case Set = 'set';

    /**
     * Get the default length for string-based types.
     */
    public function getDefaultLength(): ?int
    {
        return match ($this) {
            self::Char => 1,
            self::String => 255,
            self::Uuid => 36,
            default => null,
        };
    }

    /**
     * Check if this type supports length specification.
     */
    public function supportsLength(): bool
    {
        return match ($this) {
            self::Char,
            self::String,
            self::Binary => true,
            default => false,
        };
    }

    /**
     * Check if this type supports precision/scale.
     */
    public function supportsPrecision(): bool
    {
        return match ($this) {
            self::Decimal,
            self::Float,
            self::Double => true,
            default => false,
        };
    }

    /**
     * Check if this type supports unsigned values.
     */
    public function supportsUnsigned(): bool
    {
        return match ($this) {
            self::TinyInteger,
            self::SmallInteger,
            self::MediumInteger,
            self::Integer,
            self::BigInteger,
            self::Id,
            self::Float,
            self::Double,
            self::Decimal => true,
            default => false,
        };
    }

    /**
     * Check if this is an auto-incrementable type.
     */
    public function supportsAutoIncrement(): bool
    {
        return match ($this) {
            self::TinyInteger,
            self::SmallInteger,
            self::MediumInteger,
            self::Integer,
            self::BigInteger,
            self::Id => true,
            default => false,
        };
    }
}
