<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Schema;

/**
 * Fluent column definition builder.
 * 
 * Represents a column in a table blueprint with chainable modifiers.
 * 
 * @example
 * $column = new Column('email', ColumnType::String);
 * $column->length(255)->nullable()->unique();
 */
class Column
{
    /**
     * @param array<string, mixed> $enumValues
     */
    private ?int $length = null;
    /**
     * @param array<string, mixed> $enumValues
     */
    private ?int $precision = null;
    /**
     * @param array<string, mixed> $enumValues
     */
    private ?int $scale = null;
    /**
     * @param array<string, mixed> $enumValues
     */
    private bool $nullable = false;
    /**
     * @param array<string, mixed> $enumValues
     */
    private bool $unsigned = false;
    /**
     * @param array<string, mixed> $enumValues
     */
    private bool $autoIncrement = false;
    /**
     * @param array<string, mixed> $enumValues
     */
    private bool $primary = false;
    /**
     * @param array<string, mixed> $enumValues
     */
    private bool $unique = false;
    /**
     * @param array<string, mixed> $enumValues
     */
    private bool $index = false;
    /**
     * @param array<string, mixed> $enumValues
     */
    private mixed $default = null;
    /**
     * @param array<string, mixed> $enumValues
     */
    private bool $hasDefault = false;
    /**
     * @param array<string, mixed> $enumValues
     */
    private ?string $after = null;
    /**
     * @param array<string, mixed> $enumValues
     */
    private ?string $comment = null;
    /**
     * @param array<string, mixed> $enumValues
     */
    private ?string $charset = null;
    /**
     * @param array<string, mixed> $enumValues
     */
    private ?string $collation = null;
    /** @var array<string, mixed> */

    private array $enumValues = [];
    
    // Foreign key properties
    private ?string $referencesTable = null;
    private ?string $referencesColumn = null;
    private ?string $onDelete = null;
    private ?string $onUpdate = null;
    
    // Column modification flag
    private bool $isChange = false;

    public function __construct(
        private readonly string $name,
        private readonly ColumnType $type,
    ) {
        // Set default length for types that have one
        $this->length = $type->getDefaultLength();
    }

    // =========================================================================
    // Getters
    // =========================================================================

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): ColumnType
    {
        return $this->type;
    }

    public function getLength(): ?int
    {
        return $this->length;
    }

    public function getPrecision(): ?int
    {
        return $this->precision;
    }

    public function getScale(): ?int
    {
        return $this->scale;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function isUnsigned(): bool
    {
        return $this->unsigned;
    }

    public function isAutoIncrement(): bool
    {
        return $this->autoIncrement;
    }

    public function isPrimary(): bool
    {
        return $this->primary;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }

    public function hasIndex(): bool
    {
        return $this->index;
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    public function hasDefaultValue(): bool
    {
        return $this->hasDefault;
    }

    public function getAfter(): ?string
    {
        return $this->after;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getCharset(): ?string
    {
        return $this->charset;
    }

    public function getCollation(): ?string
    {
        return $this->collation;
    }

    /**

     * @return array<string, mixed>

     */

public function getEnumValues(): array

    {
        return $this->enumValues;
    }

    public function getReferencesTable(): ?string
    {
        return $this->referencesTable;
    }

    public function getReferencesColumn(): ?string
    {
        return $this->referencesColumn;
    }

    public function getOnDelete(): ?string
    {
        return $this->onDelete;
    }

    public function getOnUpdate(): ?string
    {
        return $this->onUpdate;
    }

    // =========================================================================
    // Fluent Setters
    // =========================================================================

    /**
     * Set the column length (for string/char types).
     */
    public function length(int $length): static
    {
        $this->length = $length;
        return $this;
    }

    /**
     * Set precision and scale (for decimal types).
     */
    public function precision(int $precision, int $scale = 0): static
    {
        $this->precision = $precision;
        $this->scale = $scale;
        return $this;
    }

    /**
     * Allow NULL values.
     */
    public function nullable(bool $nullable = true): static
    {
        $this->nullable = $nullable;
        return $this;
    }

    /**
     * Make the column unsigned (for numeric types).
     */
    public function unsigned(bool $unsigned = true): static
    {
        $this->unsigned = $unsigned;
        return $this;
    }

    /**
     * Set auto-increment.
     */
    public function autoIncrement(bool $autoIncrement = true): static
    {
        $this->autoIncrement = $autoIncrement;
        return $this;
    }

    /**
     * Make this the primary key.
     */
    public function primary(bool $primary = true): static
    {
        $this->primary = $primary;
        return $this;
    }

    /**
     * Add a unique constraint.
     */
    public function unique(bool $unique = true): static
    {
        $this->unique = $unique;
        return $this;
    }

    /**
     * Add an index.
     */
    public function index(bool $index = true): static
    {
        $this->index = $index;
        return $this;
    }

    /**
     * Set default value.
     */
    public function default(mixed $value): static
    {
        $this->default = $value;
        $this->hasDefault = true;
        return $this;
    }

    /**
     * Place column after another column (MySQL).
     */
    public function after(string $column): static
    {
        $this->after = $column;
        return $this;
    }

    /**
     * Set column comment.
     */
    public function comment(string $comment): static
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * Set character set.
     */
    public function charset(string $charset): static
    {
        $this->charset = $charset;
        return $this;
    }

    /**
     * Set collation.
     */
    public function collation(string $collation): static
    {
        $this->collation = $collation;
        return $this;
    }

    /**
     * Set allowed values for ENUM type.
      * @param array<int|string, mixed> $values
     */
    public function values(array $values): static
    {
        $this->enumValues = $values;
        return $this;
    }

    /**
     * Mark column for modification (use in ALTER TABLE operations).
     * 
     * When chained with column definition methods, generates MODIFY COLUMN
     * instead of ADD COLUMN in the resulting SQL.
     */
    public function change(): static
    {
        $this->isChange = true;
        return $this;
    }

    /**
     * Check if this column is marked for modification.
     */
    public function isChange(): bool
    {
        return $this->isChange;
    }

    /**
     * Set default to CURRENT_TIMESTAMP for timestamp columns.
     */
    public function useCurrent(): static
    {
        return $this->default('CURRENT_TIMESTAMP');
    }

    /**
     * Set default to CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP.
     */
    public function useCurrentOnUpdate(): static
    {
        $this->default = 'CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';
        $this->hasDefault = true;
        return $this;
    }

    // =========================================================================
    // Foreign Key Methods
    // =========================================================================

    /**
     * Set up a foreign key reference.
     */
    public function references(string $column): static
    {
        $this->referencesColumn = $column;
        return $this;
    }

    /**
     * Set the table for the foreign key.
     */
    public function on(string $table): static
    {
        $this->referencesTable = $table;
        return $this;
    }

    /**
     * Set ON DELETE action.
     */
    public function onDelete(string $action): static
    {
        $this->onDelete = strtoupper($action);
        return $this;
    }

    /**
     * Set ON UPDATE action.
     */
    public function onUpdate(string $action): static
    {
        $this->onUpdate = strtoupper($action);
        return $this;
    }

    /**
     * Shortcut for onDelete('CASCADE').
     */
    public function cascadeOnDelete(): static
    {
        return $this->onDelete('CASCADE');
    }

    /**
     * Shortcut for onDelete('SET NULL').
     */
    public function nullOnDelete(): static
    {
        return $this->onDelete('SET NULL');
    }

    /**
     * Shortcut for onDelete('RESTRICT').
     */
    public function restrictOnDelete(): static
    {
        return $this->onDelete('RESTRICT');
    }

    /**
     * Set foreign key constraint using conventional naming.
     * 
     * Automatically determines the referenced table and column based on column name.
     * For example, 'country_id' references 'countries.id'.
     * 
     * @param string|null $table The referenced table (auto-guessed from column name if null)
     * @param string $column The referenced column (defaults to 'id')
     */
    public function constrained(?string $table = null, string $column = 'id'): static
    {
        // If no table provided, guess from column name
        if ($table === null) {
            $table = $this->guessTableFromColumnName();
        }

        $this->referencesTable = $table;
        $this->referencesColumn = $column;

        return $this;
    }

    /**
     * Guess the referenced table name from the column name.
     * 
     * Examples:
     * - 'country_id' -> 'countries'
     * - 'user_id' -> 'users'
     * - 'category' -> 'categories'
     */
    private function guessTableFromColumnName(): string
    {
        $name = $this->name;
        
        // Remove _id suffix if present
        if (str_ends_with($name, '_id')) {
            $name = substr($name, 0, -3);
        }
        
        // Simple pluralization (handles common cases)
        return $this->pluralize($name);
    }

    /**
     * Simple pluralization for common English words.
     */
    private function pluralize(string $word): string
    {
        // Already plural
        if (str_ends_with($word, 's')) {
            return $word;
        }
        
        // Words ending in 'y' after consonant -> 'ies'
        if (str_ends_with($word, 'y') && strlen($word) > 1) {
            $beforeY = substr($word, -2, 1);
            if (!in_array($beforeY, ['a', 'e', 'i', 'o', 'u'], true)) {
                return substr($word, 0, -1) . 'ies';
            }
        }
        
        // Default: add 's'
        return $word . 's';
    }

    /**
     * Check if this column has a foreign key.
     */
    public function hasForeignKey(): bool
    {
        return $this->referencesTable !== null && $this->referencesColumn !== null;
    }
}
