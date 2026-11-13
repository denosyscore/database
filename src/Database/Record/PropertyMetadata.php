<?php

declare(strict_types=1);

namespace Denosys\Database\Record;

/** One column property of a record class. */
final readonly class PropertyMetadata
{
    /**
     * @param string $type A builtin type name or a class name.
     * @param class-string<Cast>|null $cast
     */
    public function __construct(
        public string $name,
        public string $column,
        public string $type,
        public bool $nullable,
        public bool $hasDefault,
        public ?string $cast,
    ) {
    }
}
