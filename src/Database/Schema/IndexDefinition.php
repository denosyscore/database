<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Schema;

/**
 * Represents an index definition in a table blueprint.
 */
class IndexDefinition
{
    /**
     * @param array<string> $columns
     */
    public function __construct(
        /**
         * @param array<string> $columns
         */
        private readonly array $columns,
        private readonly IndexType $type = IndexType::Index,
        private ?string $name = null,
    ) {
        // Auto-generate name if not provided
        if ($this->name === null) {
            $prefix = match ($type) {
                IndexType::Primary => 'pk',
                IndexType::Unique => 'uq',
                IndexType::Index => 'idx',
                IndexType::Fulltext => 'ft',
                IndexType::Spatial => 'sp',
            };
            $this->name = $prefix . '_' . implode('_', $this->columns);
        }
    }

    /**

     * @return array<string, mixed>

     */

public function getColumns(): array

    {
        return $this->columns;
    }

    public function getType(): IndexType
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
