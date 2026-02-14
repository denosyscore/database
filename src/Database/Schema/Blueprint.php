<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Schema;

use Closure;

/**
 * Blueprint for defining table structure.
 * 
 * Represents a table's columns, indexes, and constraints.
 * Used in create() and table() schema operations.
 * 
 * @example
 * $schema->create('users', function (Blueprint $table) {
 *     $table->id();
 *     $table->column('email', ColumnType::String)->unique();
 *     $table->timestamps();
 * });
 */
class Blueprint
{
    /** @var Column[] */
    /** @var array<string, mixed> */

    private array $columns = [];
    
    /** @var IndexDefinition[] */
    /** @var array<string, mixed> */

    private array $indexes = [];
    
    /** @var ForeignKeyDefinition[] */
    /** @var array<string, mixed> */

    private array $foreignKeys = [];
    
    /** @var string[] */
    /** @var array<string, mixed> */

    private array $dropColumns = [];
    
    /** @var array<string, string> */
private array $renameColumns = [];
    
    /** @var string[] */
    /** @var array<string, mixed> */

    private array $dropIndexes = [];
    
    /** @var string[] */
    /** @var array<string, mixed> */

    private array $dropForeignKeys = [];
    
    private ?string $engine = null;
    private ?string $charset = null;
    private ?string $collation = null;
    private ?string $comment = null;
    private bool $temporary = false;

    public function __construct(
        private readonly string $table,
    ) {}

    // =========================================================================
    // Column Definition Methods
    // =========================================================================

    /**
     * Add a column with a specific type.
     */
    public function column(string $name, ColumnType $type): Column
    {
        $column = new Column($name, $type);
        $this->columns[] = $column;
        return $column;
    }

    /**
     * Add an auto-incrementing big integer primary key column.
     */
    public function id(string $name = 'id'): Column
    {
        return $this->column($name, ColumnType::Id)
            ->unsigned()
            ->autoIncrement()
            ->primary();
    }

    /**
     * Add a UUID primary key column.
     */
    public function uuid(string $name = 'id'): Column
    {
        return $this->column($name, ColumnType::Uuid)->primary();
    }

    /**
     * Add a foreign ID column (unsigned big integer).
     */
    public function foreignId(string $name): Column
    {
        return $this->column($name, ColumnType::BigInteger)->unsigned();
    }

    /**
     * Add created_at and updated_at timestamp columns.
     */
    public function timestamps(): void
    {
        $this->column('created_at', ColumnType::Timestamp)->nullable();
        $this->column('updated_at', ColumnType::Timestamp)->nullable();
    }

    /**
     * Add a deleted_at timestamp for soft deletes.
     */
    public function softDeletes(string $name = 'deleted_at'): Column
    {
        return $this->column($name, ColumnType::Timestamp)->nullable();
    }

    /**
     * Add a remember_token column.
     */
    public function rememberToken(): Column
    {
        return $this->column('remember_token', ColumnType::String)
            ->length(100)
            ->nullable();
    }

    // =========================================================================
    // Shortcut Column Methods
    // =========================================================================

    /**
     * Add a string column.
     */
    public function string(string $name, int $length = 255): Column
    {
        return $this->column($name, ColumnType::String)->length($length);
    }

    /**
     * Add a text column.
     */
    public function text(string $name): Column
    {
        return $this->column($name, ColumnType::Text);
    }

    /**
     * Add an integer column.
     */
    public function integer(string $name): Column
    {
        return $this->column($name, ColumnType::Integer);
    }

    /**
     * Add a big integer column.
     */
    public function bigInteger(string $name): Column
    {
        return $this->column($name, ColumnType::BigInteger);
    }

    /**
     * Add a boolean column.
     */
    public function boolean(string $name): Column
    {
        return $this->column($name, ColumnType::Boolean);
    }

    /**
     * Add a date column.
     */
    public function date(string $name): Column
    {
        return $this->column($name, ColumnType::Date);
    }

    /**
     * Add a datetime column.
     */
    public function dateTime(string $name): Column
    {
        return $this->column($name, ColumnType::DateTime);
    }

    /**
     * Add a timestamp column.
     */
    public function timestamp(string $name): Column
    {
        return $this->column($name, ColumnType::Timestamp);
    }

    /**
     * Add a decimal column.
     */
    public function decimal(string $name, int $precision = 8, int $scale = 2): Column
    {
        return $this->column($name, ColumnType::Decimal)->precision($precision, $scale);
    }

    /**
     * Add a JSON column.
     */
    public function json(string $name): Column
    {
        return $this->column($name, ColumnType::Json);
    }

    /**
     * Add an enum column.
      * @param array<int|string, mixed> $values
     */
    public function enum(string $name, array $values): Column
    {
        return $this->column($name, ColumnType::Enum)->values($values);
    }

    /**
     * Add a medium text column.
     */
    public function mediumText(string $name): Column
    {
        return $this->column($name, ColumnType::MediumText);
    }

    /**
     * Add a long text column.
     */
    public function longText(string $name): Column
    {
        return $this->column($name, ColumnType::LongText);
    }

    /**
     * Add a tiny integer column.
     */
    public function tinyInteger(string $name): Column
    {
        return $this->column($name, ColumnType::TinyInteger);
    }

    /**
     * Add a small integer column.
     */
    public function smallInteger(string $name): Column
    {
        return $this->column($name, ColumnType::SmallInteger);
    }

    /**
     * Add an unsigned integer column.
     */
    public function unsignedInteger(string $name): Column
    {
        return $this->column($name, ColumnType::Integer)->unsigned();
    }

    /**
     * Add an unsigned tiny integer column.
     */
    public function unsignedTinyInteger(string $name): Column
    {
        return $this->column($name, ColumnType::TinyInteger)->unsigned();
    }

    /**
     * Add an unsigned small integer column.
     */
    public function unsignedSmallInteger(string $name): Column
    {
        return $this->column($name, ColumnType::SmallInteger)->unsigned();
    }

    /**
     * Add an unsigned big integer column.
     */
    public function unsignedBigInteger(string $name): Column
    {
        return $this->column($name, ColumnType::BigInteger)->unsigned();
    }

    // =========================================================================
    // Index Methods
    // =========================================================================

    /**
     * Add a primary key.
      * @param array<string> $columns
     */
    public function primary(string|array $columns, ?string $name = null): static
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $this->indexes[] = new IndexDefinition($columns, IndexType::Primary, $name);
        return $this;
    }

    /**
     * Add a unique index.
      * @param array<string> $columns
     */
    public function unique(string|array $columns, ?string $name = null): static
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $this->indexes[] = new IndexDefinition($columns, IndexType::Unique, $name);
        return $this;
    }

    /**
     * Add a regular index.
      * @param array<string> $columns
     */
    public function index(string|array $columns, ?string $name = null): static
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $this->indexes[] = new IndexDefinition($columns, IndexType::Index, $name);
        return $this;
    }

    /**
     * Add a fulltext index.
      * @param array<string> $columns
     */
    public function fulltext(string|array $columns, ?string $name = null): static
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $this->indexes[] = new IndexDefinition($columns, IndexType::Fulltext, $name);
        return $this;
    }

    // =========================================================================
    // Foreign Key Methods
    // =========================================================================

    /**
     * Add a foreign key constraint.
     */
    public function foreign(string $column): ForeignKeyBuilder
    {
        return new ForeignKeyBuilder($column, $this);
    }

    /**
     * Add a completed foreign key definition (called by ForeignKeyBuilder).
     * 
     * @internal
     */
    public function addForeignKey(ForeignKeyDefinition $foreignKey): void
    {
        $this->foreignKeys[] = $foreignKey;
    }

    // =========================================================================
    // Modification Methods (for ALTER TABLE)
    // =========================================================================

    /**
     * Drop columns.
     */
    public function dropColumn(string ...$columns): static
    {
        $this->dropColumns = array_merge($this->dropColumns, $columns);
        return $this;
    }

    /**
     * Rename a column.
     */
    public function renameColumn(string $from, string $to): static
    {
        $this->renameColumns[$from] = $to;
        return $this;
    }

    /**
     * Drop an index.
      * @param array<string> $columns
     */
    public function dropIndex(string|array $columns): static
    {
        if (is_array($columns)) {
            $name = 'idx_' . implode('_', $columns);
        } else {
            $name = $columns;
        }
        $this->dropIndexes[] = $name;
        return $this;
    }

    /**
     * Drop a unique index.
      * @param array<string> $columns
     */
    public function dropUnique(string|array $columns): static
    {
        if (is_array($columns)) {
            $name = 'uq_' . implode('_', $columns);
        } else {
            $name = $columns;
        }
        $this->dropIndexes[] = $name;
        return $this;
    }

    /**
     * Drop a foreign key.
     */
    public function dropForeign(string $name): static
    {
        $this->dropForeignKeys[] = $name;
        return $this;
    }

    // =========================================================================
    // Table Options
    // =========================================================================

    /**
     * Set table engine (MySQL).
     */
    public function engine(string $engine): static
    {
        $this->engine = $engine;
        return $this;
    }

    /**
     * Set table charset.
     */
    public function charset(string $charset): static
    {
        $this->charset = $charset;
        return $this;
    }

    /**
     * Set table collation.
     */
    public function collation(string $collation): static
    {
        $this->collation = $collation;
        return $this;
    }

    /**
     * Set table comment.
     */
    public function comment(string $comment): static
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * Create as temporary table.
     */
    public function temporary(): static
    {
        $this->temporary = true;
        return $this;
    }

    // =========================================================================
    // Getters
    // =========================================================================

    public function getTable(): string
    {
        return $this->table;
    }

    /** @return Column[] */
    /**
     * @return array<string, mixed>
     */
public function getColumns(): array
    {
        return $this->columns;
    }

    /** @return IndexDefinition[] */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /** @return ForeignKeyDefinition[] */
    public function getForeignKeys(): array
    {
        return $this->foreignKeys;
    }

    /** @return string[] */
    public function getDropColumns(): array
    {
        return $this->dropColumns;
    }

    /** @return array<string, string> */
    public function getRenameColumns(): array
    {
        return $this->renameColumns;
    }

    /** @return string[] */
    public function getDropIndexes(): array
    {
        return $this->dropIndexes;
    }

    /** @return string[] */
    public function getDropForeignKeys(): array
    {
        return $this->dropForeignKeys;
    }

    public function getEngine(): ?string
    {
        return $this->engine;
    }

    public function getCharset(): ?string
    {
        return $this->charset;
    }

    public function getCollation(): ?string
    {
        return $this->collation;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function isTemporary(): bool
    {
        return $this->temporary;
    }

    /**
     * Check if this blueprint is for creating a new table vs modifying.
     */
    public function isCreating(): bool
    {
        return !empty($this->columns) && 
               empty($this->dropColumns) && 
               empty($this->renameColumns);
    }
}
