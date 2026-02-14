<?php

declare(strict_types=1);

namespace Denosys\Database\Exceptions;

use RuntimeException;

/**
 * Exception thrown when a model cannot be found.
 * 
 * This exception is thrown by methods like findOrFail() and firstOrFail()
 * when the requested model does not exist in the database.
 * 
 * The exception handler should convert this to a 404 HTTP response.
 */
class ModelNotFoundException extends RuntimeException
{
    protected string $model = '';

    /** @var array<mixed> */
    protected array $ids = [];

    /**
     * Set the affected model and IDs.
     *
     * @param string $model The model class name
     * @param array<mixed>|int|string $ids The IDs that were not found
     * @return $this
     */
    public function setModel(string $model, array|int|string $ids = []): static
    {
        $this->model = $model;
        $this->ids = (array) $ids;

        $this->message = "No query results for model [{$model}]";

        if (!empty($this->ids)) {
            $this->message .= ' ' . implode(', ', $this->ids);
        }

        return $this;
    }

    /**
     * Get the affected model class.
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Get the affected model IDs.
     *
     * @return array<mixed>
     */
    public function getIds(): array
    {
        return $this->ids;
    }
}
