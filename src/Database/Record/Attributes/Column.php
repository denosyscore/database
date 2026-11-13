<?php

declare(strict_types=1);

namespace Denosys\Database\Record\Attributes;

use Attribute;

/** Names a property's column. Without it the column is the snake-case property name. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Column
{
    public function __construct(public string $name)
    {
    }
}
