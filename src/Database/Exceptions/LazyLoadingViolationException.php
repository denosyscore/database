<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Exceptions;

use RuntimeException;

class LazyLoadingViolationException extends RuntimeException
{
    public function __construct(string $model, string $relation)
    {
        parent::__construct(
            "Attempted to lazy load [{$relation}] on model [{$model}]. " .
            "Use eager loading with ->with('{$relation}') to prevent N+1 queries."
        );
    }
}
