<?php

declare(strict_types=1);

namespace Denosys\Database\Record;

use Denosys\Database\Exceptions\RecordNotFound;

/**
 * A row-backed object. Implement it with the Persists trait:
 *
 *     #[Table('users')]
 *     final class User implements Record
 *     {
 *         use Persists;
 *
 *         public ?int $id = null;
 *         public string $email;
 *     }
 *
 * The class's public properties are its columns. Route binding, auth and
 * other tooling load any record class through this interface.
 */
interface Record
{
    /** @return RecordQuery<static> */
    public static function query(): RecordQuery;

    public static function find(int|string $id): ?static;

    /** @throws RecordNotFound */
    public static function findOrFail(int|string $id): static;

    /** Inserts a new record, or updates the changed columns of a saved one. */
    public function save(): void;

    public function delete(): void;

    /** Reloads every column and forgets loaded relations. */
    public function refresh(): void;

    /** Whether the record was loaded or saved, and not deleted since. */
    public function exists(): bool;
}
