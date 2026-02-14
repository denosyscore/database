<?php

declare(strict_types=1);

namespace Denosys\Database\Schema;

/**
 * Represents a foreign key constraint.
 */
class ForeignKeyDefinition
{
    private ?string $onDelete = null;
    private ?string $onUpdate = null;

    public function __construct(
        private readonly string $column,
        private readonly string $referencesTable,
        private readonly string $referencesColumn,
        private ?string $name = null,
    ) {
        // Auto-generate name if not provided
        if ($this->name === null) {
            $this->name = "fk_{$column}_{$referencesTable}_{$referencesColumn}";
        }
    }

    public function getColumn(): string
    {
        return $this->column;
    }

    public function getReferencesTable(): string
    {
        return $this->referencesTable;
    }

    public function getReferencesColumn(): string
    {
        return $this->referencesColumn;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getOnDelete(): ?string
    {
        return $this->onDelete;
    }

    public function getOnUpdate(): ?string
    {
        return $this->onUpdate;
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
}
