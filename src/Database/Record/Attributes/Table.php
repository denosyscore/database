<?php

declare(strict_types=1);

namespace Denosys\Database\Record\Attributes;

use Attribute;

/**
 * Names a record's table and, optionally, its connection. Without it the
 * table is the snake-case plural of the class name.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Table
{
    public function __construct(
        public string $name,
        public ?string $connection = null,
    ) {
    }
}
