<?php

declare(strict_types=1);

namespace Denosys\Database\Record\Relations;

use Closure;
use Denosys\Database\Record\Record;
use Denosys\Database\Record\RecordQuery;
use Denosys\Database\Record\RecordStore;
use LogicException;

/**
 * Key matching shared by the relation classes.
 *
 * @internal
 */
final class Matching
{
    /**
     * The distinct, non-null values of one column across records.
     *
     * @param list<Record> $records
     * @return list<int|string>
     */
    public static function values(RecordStore $store, array $records, string $column): array
    {
        $values = [];
        foreach ($records as $record) {
            $value = $store->columnValue($record, $column);
            if (is_int($value) || is_string($value)) {
                $values[(string) $value] = $value;
            }
        }

        return array_values($values);
    }

    /** A dictionary key for a column value, so 1 and '1' match; NULL has none and matches nothing. */
    public static function key(mixed $value): ?string
    {
        return is_int($value) || is_string($value) ? (string) $value : null;
    }

    /**
     * @template R of Record
     * @param RecordQuery<R> $query
     * @param (Closure(RecordQuery<R>): RecordQuery<R>)|null $constraint
     * @param list<Closure> $nested
     * @return RecordQuery<R>
     */
    public static function apply(RecordQuery $query, ?Closure $constraint, array $nested): RecordQuery
    {
        $query = $constraint === null ? $query : $constraint($query);

        return $nested === [] ? $query : $query->with(...$nested);
    }

    /**
     * The group for a parent's key value; a NULL key gets $default.
     *
     * @template V
     * @template D
     * @param array<string, V> $groups
     * @param D $default
     * @return V|D
     */
    public static function lookup(array $groups, mixed $value, mixed $default): mixed
    {
        $key = self::key($value);

        return $key === null ? $default : ($groups[$key] ?? $default);
    }

    /**
     * The query an eager load runs for every parent at once. A limit or
     * offset there would apply across all parents, so it is refused.
     *
     * @template R of Record
     * @param RecordQuery<R> $query
     * @param (Closure(RecordQuery<R>): RecordQuery<R>)|null $constraint
     * @param list<Closure> $nested
     * @return RecordQuery<R>
     *
     * @throws LogicException
     */
    public static function eager(RecordQuery $query, ?Closure $constraint, array $nested): RecordQuery
    {
        $query = self::apply($query, $constraint, $nested);
        if ($query->toBase()->isPaged()) {
            throw new LogicException('An eager-loaded relation cannot use limit() or offset(): they would apply to all parents together. Use the relation query() per record.');
        }

        return $query;
    }

    /**
     * @template R of Record
     * @param (Closure(RecordQuery<R>): RecordQuery<R>)|null $first
     * @param Closure(RecordQuery<R>): RecordQuery<R> $then
     * @return Closure(RecordQuery<R>): RecordQuery<R>
     */
    public static function compose(?Closure $first, Closure $then): Closure
    {
        return $first === null ? $then : static fn (RecordQuery $query): RecordQuery => $then($first($query));
    }
}
