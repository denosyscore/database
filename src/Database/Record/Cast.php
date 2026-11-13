<?php

declare(strict_types=1);

namespace Denosys\Database\Record;

/**
 * Converts a column value to a property value and back, for property types
 * the store cannot convert on its own (a money value object, say).
 */
interface Cast
{
    public function fromDatabase(mixed $value): mixed;

    public function toDatabase(mixed $value): mixed;
}
