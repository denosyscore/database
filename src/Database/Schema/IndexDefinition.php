<?php

declare(strict_types=1);

namespace Denosys\Database\Schema;

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
        ?string $table = null,
    ) {
        $this->name ??= self::defaultName($type, $table, $this->columns);
    }

    /**
     * Build the default index name: {prefix}_{table}_{columns}.
     *
     * Index names are per schema in SQLite and PostgreSQL, so the table is part of the
     * name to keep identical column names on different tables from colliding.
     *
     * @param array<string> $columns
     */
    public static function defaultName(IndexType $type, ?string $table, array $columns): string
    {
        $prefix = match ($type) {
            IndexType::Primary => 'pk',
            IndexType::Unique => 'uq',
            IndexType::Index => 'idx',
            IndexType::Fulltext => 'ft',
            IndexType::Spatial => 'sp',
        };

        // A qualified table ('reporting.events') names its index by the table alone:
        // the index lives in the table's schema.
        $table = $table === null ? null : substr($table, (int) strrpos('.' . $table, '.'));
        $parts = $table === null || $table === '' ? $columns : [$table, ...$columns];

        return $prefix . '_' . implode('_', $parts);
    }

    /**
     * @return array<string>
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
