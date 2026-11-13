<?php

declare(strict_types=1);

namespace Denosys\Database\Record;

/**
 * Implements Record. It declares no properties, so a class's public
 * properties are exactly its columns.
 *
 * @phpstan-require-implements Record
 */
trait Persists
{
    /** @return RecordQuery<static> */
    public static function query(): RecordQuery
    {
        return Records::store()->query(static::class);
    }

    public static function find(int|string $id): ?static
    {
        return Records::store()->find(static::class, $id);
    }

    public static function findOrFail(int|string $id): static
    {
        return Records::store()->findOrFail(static::class, $id);
    }

    public function save(): void
    {
        Records::store()->save($this);
    }

    public function delete(): void
    {
        Records::store()->delete($this);
    }

    public function refresh(): void
    {
        Records::store()->refresh($this);
    }

    public function exists(): bool
    {
        return Records::store()->isPersisted($this);
    }
}
