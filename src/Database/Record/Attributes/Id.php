<?php

declare(strict_types=1);

namespace Denosys\Database\Record\Attributes;

use Attribute;

/** Marks the primary key property. Without it the key is the property named `id`. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Id
{
}
