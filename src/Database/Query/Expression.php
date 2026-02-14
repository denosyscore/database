<?php

declare(strict_types=1);

namespace Denosys\Database\Query;

use Stringable;

class Expression implements Stringable
{
    /**
     * The value of the expression.
     */
    protected string $value;

    /**
     * Create a new raw expression.
     */
    public function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * Get the value of the expression.
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Get the value of the expression.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}

