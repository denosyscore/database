<?php

declare(strict_types=1);

namespace Denosys\Database\Exceptions;

use RuntimeException;

/** No row has the key that was asked for. */
final class RecordNotFound extends RuntimeException
{
    /** @param class-string $record */
    public function __construct(
        public readonly string $record,
        public readonly int|string $id,
    ) {
        parent::__construct(sprintf('No %s has the key %s.', $record, var_export($id, true)));
    }
}
