<?php

declare(strict_types=1);

namespace Denosys\Database\Record\Attributes;

use Attribute;
use Denosys\Database\Record\Cast;

/** Converts a property with a Cast instead of from its type. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class CastWith
{
    /** @param class-string<Cast> $cast */
    public function __construct(public string $cast)
    {
    }
}
