<?php

declare(strict_types=1);

namespace Denosys\Database\Exceptions;

use LogicException;

/**
 * A relation was read before it was loaded. Load it with the query's with(),
 * or run its query() when a query per record is intended.
 */
final class RelationNotLoaded extends LogicException
{
    /** @param class-string $record */
    public function __construct(public readonly string $record, public readonly string $relation)
    {
        parent::__construct("The {$relation} relation of {$record} is not loaded. Load it with with(), or call query() to run a query.");
    }
}
