<?php

declare(strict_types=1);

namespace Denosys\Database\Exceptions;

use LogicException;

/** A record class, or a value written to or read from one, breaks the record rules. */
final class InvalidRecord extends LogicException
{
}
