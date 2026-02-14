<?php

declare(strict_types=1);

namespace Denosys\Database\Exceptions;

use RuntimeException;

class MassAssignmentException extends RuntimeException
{
    /**
     * @param array<string> $keys
     */
    public function __construct(string $model, array $keys)
    {
        $keyList = implode(', ', $keys);
        parent::__construct(
            "Cannot mass-assign [{$keyList}] on [{$model}]. " .
            "Add to \$fillable or use forceFill()."
        );
    }
}
