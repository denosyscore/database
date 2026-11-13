<?php

declare(strict_types=1);

namespace Denosys\Database\Record;

use DateTimeImmutable;
use DateTimeZone;
use Denosys\Database\Connection\Connection;
use Denosys\Database\Connection\ConnectionManager;
use Denosys\Database\Exceptions\InvalidRecord;
use Denosys\Database\Exceptions\RecordNotFound;
use ReflectionClass;
use ReflectionProperty;
use WeakMap;

/**
 * Loads, tracks and saves records. Records reach it through Persists and
 * the Records locator; applications do not call it directly.
 *
 * The store keeps no identity map: loading a row twice gives two objects.
 * What it knows about a record (its snapshot and loaded relations) lives in
 * a WeakMap and is released with the record.
 *
 * @internal
 */
final class RecordStore
{
    /** @var WeakMap<object, RowState> */
    private WeakMap $state;

    /** @var array<string, ValueCaster> */
    private array $casters = [];

    public function __construct(private readonly ConnectionManager $connections)
    {
        $this->state = new WeakMap();
    }

    /** @param class-string $class */
    public function connectionFor(string $class): Connection
    {
        return $this->connections->connection(RecordMetadata::of($class)->connection);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return RecordQuery<T>
     */
    public function query(string $class): RecordQuery
    {
        $table = RecordMetadata::of($class)->table;

        // Select only the record's table, so a joined column never overwrites one of its own.
        return new RecordQuery($this, $class, $this->connectionFor($class)->table($table)->select([$table . '.*']));
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    public function find(string $class, int|string $id): ?object
    {
        return $this->query($class)->find($id);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     *
     * @throws RecordNotFound
     */
    public function findOrFail(string $class, int|string $id): object
    {
        return $this->find($class, $id) ?? throw new RecordNotFound($class, $id);
    }

    /**
     * Builds a persisted record from a row. Columns without a property are
     * ignored; properties without a column keep their defaults.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $row
     * @return T
     */
    public function hydrate(string $class, array $row): object
    {
        $record = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $this->fill($record, $row);

        return $record;
    }

    public function isPersisted(object $record): bool
    {
        return isset($this->state[$record]);
    }

    /**
     * Inserts a new record or updates the changed columns of a persisted one.
     * A persisted record with no changes runs no query.
     */
    public function save(object $record): void
    {
        $meta = RecordMetadata::of($record::class);
        $state = $this->state[$record] ?? null;

        if ($state === null) {
            $this->insert($meta, $record);

            return;
        }

        $changes = $this->changes($meta, $record, $state);
        if ($changes === []) {
            return;
        }

        $key = $this->loadedKey($meta, $state);
        if (array_key_exists($meta->id->column, $changes)) {
            throw new InvalidRecord("The key of a saved {$meta->class} cannot change; insert a new record instead.");
        }

        if (isset($meta->properties['updatedAt']) && !array_key_exists($meta->properties['updatedAt']->column, $changes)) {
            self::set($record, 'updatedAt', self::now());
            $changes = $this->changes($meta, $record, $state);
        }

        $this->connectionFor($record::class)->table($meta->table)
            ->where($meta->id->column, '=', $key)
            ->update($changes);
        $state->snapshot = [...$state->snapshot, ...$changes];
    }

    /** @throws InvalidRecord when the record was never saved */
    public function delete(object $record): void
    {
        $meta = RecordMetadata::of($record::class);
        if (!$this->isPersisted($record)) {
            throw new InvalidRecord("{$meta->class} cannot be deleted: it was never saved.");
        }

        $this->connectionFor($record::class)->table($meta->table)
            ->where($meta->id->column, '=', $this->loadedKey($meta, $this->state[$record]))
            ->delete();
        unset($this->state[$record]);
    }

    /**
     * Reloads every column from the database and forgets loaded relations.
     *
     * @throws InvalidRecord when the record was never saved
     * @throws RecordNotFound when its row is gone
     */
    public function refresh(object $record): void
    {
        $meta = RecordMetadata::of($record::class);
        if (!$this->isPersisted($record)) {
            throw new InvalidRecord("{$meta->class} cannot be refreshed: it was never saved.");
        }

        $id = $this->loadedKey($meta, $this->state[$record]);
        $row = $this->connectionFor($record::class)->table($meta->table)->where($meta->id->column, '=', $id)->first();
        if ($row === null) {
            throw new RecordNotFound($meta->class, $id);
        }

        $this->fill($record, self::row($row));
    }

    /** @internal for tests: how many records the store is tracking */
    public function stateCount(): int
    {
        return count($this->state);
    }

    /** @internal for relations */
    public function rowState(object $record): ?RowState
    {
        return $this->state[$record] ?? null;
    }

    /**
     * The database value of one column of a record, for matching keys.
     *
     * @internal for relations
     */
    public function columnValue(object $record, string $column): mixed
    {
        $meta = RecordMetadata::of($record::class);
        $property = $meta->propertyForColumn($column);

        return self::initialized($record, $property->name)
            ? $this->caster($meta->class)->toDatabase($property, self::get($record, $property->name))
            : null;
    }

    /**
     * @internal for relations
     * @return array{bool, mixed} whether the relation is loaded, and its value
     */
    public function relation(object $record, string $key): array
    {
        $state = $this->state[$record] ?? null;

        return $state !== null && array_key_exists($key, $state->relations)
            ? [true, $state->relations[$key]]
            : [false, null];
    }

    /** @internal for relations */
    public function setRelation(object $record, string $key, mixed $value): void
    {
        $state = $this->state[$record] ?? throw new InvalidRecord($record::class . ' must be saved before a relation can be loaded on it.');
        $state->relations[$key] = $value;
    }

    /**
     * @internal for relations
     * @param array<string, mixed> $values
     */
    public function setPivot(object $related, string $key, array $values): void
    {
        $state = $this->state[$related] ?? throw new InvalidRecord($related::class . ' is not loaded.');
        $state->pivots[$key] = $values;
    }

    /**
     * @internal for relations
     * @return array<string, mixed>|null
     */
    public function pivot(object $related, string $key): ?array
    {
        return ($this->state[$related] ?? null)?->pivots[$key] ?? null;
    }

    /** @param array<string, mixed> $row */
    private function fill(object $record, array $row): void
    {
        $meta = RecordMetadata::of($record::class);
        $caster = $this->caster($record::class);

        foreach ($meta->properties as $property) {
            if (array_key_exists($property->column, $row)) {
                self::set($record, $property->name, $caster->fromDatabase($meta, $property, $row[$property->column]));
            }
        }

        // Only the columns the row held are known; other properties count as changed.
        $this->state[$record] = new RowState(array_intersect_key($this->values($meta, $record), $row));
    }

    private function insert(RecordMetadata $meta, object $record): void
    {
        $now = self::now();
        foreach (['createdAt', 'updatedAt'] as $name) {
            if (isset($meta->properties[$name]) && (!self::initialized($record, $name) || self::get($record, $name) === null)) {
                self::set($record, $name, $now);
            }
        }

        $values = $this->values($meta, $record);
        $complete = count($values) === count($meta->properties);
        if (array_key_exists($meta->id->column, $values) && $values[$meta->id->column] === null) {
            unset($values[$meta->id->column]);
        }

        $id = $this->connectionFor($meta->class)->table($meta->table)->insertGetId($values, $meta->id->column);
        if (!self::initialized($record, $meta->id->name) || self::get($record, $meta->id->name) === null) {
            self::set($record, $meta->id->name, $this->caster($meta->class)->fromDatabase($meta, $meta->id, $id));
        }

        $this->state[$record] = new RowState($this->values($meta, $record));
        if (!$complete) {
            $this->refresh($record);
        }
    }

    /**
     * The changed columns of a persisted record, as database values.
     *
     * @return array<string, mixed>
     */
    private function changes(RecordMetadata $meta, object $record, RowState $state): array
    {
        $changes = [];
        foreach ($this->values($meta, $record) as $column => $value) {
            if (!array_key_exists($column, $state->snapshot) || $state->snapshot[$column] !== $value) {
                $changes[$column] = $value;
            }
        }

        return $changes;
    }

    /**
     * The initialized properties of a record as column => database value.
     *
     * @return array<string, mixed>
     */
    private function values(RecordMetadata $meta, object $record): array
    {
        $caster = $this->caster($meta->class);
        $values = [];
        foreach ($meta->properties as $property) {
            if (self::initialized($record, $property->name)) {
                $values[$property->column] = $caster->toDatabase($property, self::get($record, $property->name));
            }
        }

        return $values;
    }

    /** The key the record was loaded or last saved with. */
    private function loadedKey(RecordMetadata $meta, RowState $state): int|string
    {
        $key = $state->snapshot[$meta->id->column] ?? null;

        return is_int($key) || is_string($key) ? $key : throw new InvalidRecord("{$meta->class} has no key value.");
    }

    /** @param class-string $class */
    private function caster(string $class): ValueCaster
    {
        $format = $this->connectionFor($class)->getGrammar()->getDateFormat();

        return $this->casters[$format] ??= new ValueCaster($format);
    }

    private static function initialized(object $record, string $property): bool
    {
        return (new ReflectionProperty($record, $property))->isInitialized($record);
    }

    /** @return array<string, mixed> */
    private static function row(object $row): array
    {
        $values = [];
        foreach (get_object_vars($row) as $column => $value) {
            if (is_string($column)) {
                $values[$column] = $value;
            }
        }

        return $values;
    }

    private static function get(object $record, string $property): mixed
    {
        return (new ReflectionProperty($record, $property))->getValue($record);
    }

    private static function set(object $record, string $property, mixed $value): void
    {
        (new ReflectionProperty($record, $property))->setValue($record, $value);
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
