<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Schema;

/**
 * Fluent builder for foreign key definitions.
 * 
 * @example
 * $table->foreign('user_id')
 *     ->references('id')
 *     ->on('users')
 *     ->cascadeOnDelete();
 */
class ForeignKeyBuilder
{
    private ?string $referencesColumn = null;
    private ?string $referencesTable = null;
    private ?string $name = null;
    private ForeignKeyDefinition $definition;

    public function __construct(
        private readonly string $column,
        private readonly Blueprint $blueprint,
    ) {}

    /**
     * Set the column being referenced.
     */
    public function references(string $column): static
    {
        $this->referencesColumn = $column;
        return $this;
    }

    /**
     * Set the table being referenced.
     */
    public function on(string $table): static
    {
        $this->referencesTable = $table;
        $this->build();
        return $this;
    }

    /**
     * Set the constraint name.
     */
    public function name(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Set ON DELETE action.
     */
    public function onDelete(string $action): static
    {
        $this->ensureBuilt();
        $this->definition->onDelete($action);
        return $this;
    }

    /**
     * Set ON UPDATE action.
     */
    public function onUpdate(string $action): static
    {
        $this->ensureBuilt();
        $this->definition->onUpdate($action);
        return $this;
    }

    /**
     * Cascade on delete.
     */
    public function cascadeOnDelete(): static
    {
        return $this->onDelete('CASCADE');
    }

    /**
     * Set null on delete.
     */
    public function nullOnDelete(): static
    {
        return $this->onDelete('SET NULL');
    }

    /**
     * Restrict on delete.
     */
    public function restrictOnDelete(): static
    {
        return $this->onDelete('RESTRICT');
    }

    /**
     * Cascade on update.
     */
    public function cascadeOnUpdate(): static
    {
        return $this->onUpdate('CASCADE');
    }

    /**
     * Build the foreign key definition and add to blueprint.
     */
    private function build(): void
    {
        if ($this->referencesColumn === null || $this->referencesTable === null) {
            throw new \InvalidArgumentException(
                'Foreign key must have both references() and on() called.'
            );
        }

        // Include source table in FK name for uniqueness across tables
        $autoName = $this->name ?? "fk_{$this->blueprint->getTable()}_{$this->column}_{$this->referencesTable}_{$this->referencesColumn}";

        $this->definition = new ForeignKeyDefinition(
            $this->column,
            $this->referencesTable,
            $this->referencesColumn,
            $autoName,
        );

        $this->blueprint->addForeignKey($this->definition);
    }

    /**
     * Ensure the definition has been built before modifying it.
     */
    private function ensureBuilt(): void
    {
        if (!isset($this->definition)) {
            throw new \LogicException(
                'Must call references() and on() before setting ON DELETE/UPDATE actions.'
            );
        }
    }
}
