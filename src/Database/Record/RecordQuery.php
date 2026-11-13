<?php

declare(strict_types=1);

namespace Denosys\Database\Record;

use Closure;
use Denosys\Database\Exceptions\RecordNotFound;
use Denosys\Database\Pagination\Ordering;
use Denosys\Database\Pagination\Page;
use Denosys\Database\Pagination\Paginated;
use Denosys\Database\Query\Builder;
use Denosys\Database\Query\Expression;
use Denosys\Database\Record\Relations\Relation;
use Generator;
use InvalidArgumentException;
use LogicException;

/**
 * A query for records of one class. It wraps the immutable query builder:
 * every modifier returns a new query and leaves this one unchanged.
 *
 * Use it to load records one entity at a time. For reports, sums and other
 * set-based work, use the query builder (toBase()) and its rows.
 *
 * @template T of object
 */
final readonly class RecordQuery
{
    /**
     * @param class-string<T> $class
     * @param list<Closure> $eager
     */
    public function __construct(
        private RecordStore $store,
        private string $class,
        private Builder $builder,
        private array $eager = [],
    ) {
    }

    /**
     * Loads relations onto the records this query returns, one query per
     * relation. Each callback returns a relation of the record it receives:
     *
     *     User::query()->with(fn (User $u) => $u->posts())->get();
     *
     * @param Closure(T): Relation<Record, covariant Record> ...$relations
     * @return self<T>
     */
    #[\NoDiscard]
    public function with(Closure ...$relations): self
    {
        return new self($this->store, $this->class, $this->builder, [...$this->eager, ...array_values($relations)]);
    }

    /**
     * @param string|(Closure(Builder): mixed) $column
     * @return self<T>
     */
    #[\NoDiscard]
    public function where(string|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->using(func_num_args() === 2 ? $this->builder->where($column, $operator) : $this->builder->where($column, $operator, $value));
    }

    /**
     * @param string|(Closure(Builder): mixed) $column
     * @return self<T>
     */
    #[\NoDiscard]
    public function orWhere(string|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->using(func_num_args() === 2 ? $this->builder->orWhere($column, $operator) : $this->builder->orWhere($column, $operator, $value));
    }

    /**
     * @param array<int|string, mixed>|Builder $values
     * @return self<T>
     */
    #[\NoDiscard]
    public function whereIn(string $column, array|Builder $values): self
    {
        return $this->using($this->builder->whereIn($column, $values));
    }

    /**
     * @param array<int|string, mixed>|Builder $values
     * @return self<T>
     */
    #[\NoDiscard]
    public function whereNotIn(string $column, array|Builder $values): self
    {
        return $this->using($this->builder->whereNotIn($column, $values));
    }

    /** @return self<T> */
    #[\NoDiscard]
    public function whereNull(string $column): self
    {
        return $this->using($this->builder->whereNull($column));
    }

    /** @return self<T> */
    #[\NoDiscard]
    public function whereNotNull(string $column): self
    {
        return $this->using($this->builder->whereNotNull($column));
    }

    /**
     * @param array<int|string, mixed> $values Exactly two bounds.
     * @return self<T>
     */
    #[\NoDiscard]
    public function whereBetween(string $column, array $values): self
    {
        return $this->using($this->builder->whereBetween($column, $values));
    }

    /** @return self<T> */
    #[\NoDiscard]
    public function whereColumn(string $first, string $operator, string $second): self
    {
        return $this->using($this->builder->whereColumn($first, $operator, $second));
    }

    /** @return self<T> */
    #[\NoDiscard]
    public function whereExists(Builder $query): self
    {
        return $this->using($this->builder->whereExists($query));
    }

    /** @return self<T> */
    #[\NoDiscard]
    public function whereNotExists(Builder $query): self
    {
        return $this->using($this->builder->whereNotExists($query));
    }

    /** @return self<T> */
    #[\NoDiscard]
    public function orderBy(string|Expression $column, string $direction = 'asc'): self
    {
        return $this->using($this->builder->orderBy($column, $direction));
    }

    /** @return self<T> */
    #[\NoDiscard]
    public function orderByDesc(string|Expression $column): self
    {
        return $this->using($this->builder->orderByDesc($column));
    }

    /** @return self<T> */
    #[\NoDiscard]
    public function latest(string $column = 'created_at'): self
    {
        return $this->using($this->builder->latest($column));
    }

    /** @return self<T> */
    #[\NoDiscard]
    public function oldest(string $column = 'created_at'): self
    {
        return $this->using($this->builder->oldest($column));
    }

    /** @return self<T> */
    #[\NoDiscard]
    public function limit(int $limit): self
    {
        return $this->using($this->builder->limit($limit));
    }

    /** @return self<T> */
    #[\NoDiscard]
    public function offset(int $offset): self
    {
        return $this->using($this->builder->offset($offset));
    }

    /** @return self<T> */
    #[\NoDiscard]
    public function lockForUpdate(): self
    {
        return $this->using($this->builder->lockForUpdate());
    }

    /** @return self<T> */
    #[\NoDiscard]
    public function sharedLock(): self
    {
        return $this->using($this->builder->sharedLock());
    }

    /**
     * Applies any query builder constraint the methods above do not cover.
     *
     * @param Closure(Builder): Builder $constraint
     * @return self<T>
     */
    #[\NoDiscard]
    public function tap(Closure $constraint): self
    {
        return $this->using($constraint($this->builder));
    }

    /** @return list<T> */
    public function get(): array
    {
        return array_map(static fn (array $pair): object => $pair[0], $this->getWithRows());
    }

    /**
     * Each record with the row it was hydrated from, so relations can read
     * columns that are not properties (pivot values).
     *
     * @internal for relations
     * @return list<array{T, array<string, mixed>}>
     */
    public function getWithRows(): array
    {
        $pairs = [];
        foreach ($this->rows($this->builder) as $row) {
            $values = self::row($row);
            $pairs[] = [$this->store->hydrate($this->class, $values), $values];
        }

        if ($pairs !== [] && $this->eager !== [] && $this->store !== Records::store()) {
            throw new LogicException('with() loads relations through Records::store(); run this query from that store.');
        }

        if ($pairs !== []) {
            $records = [];
            foreach ($pairs as [$record]) {
                if ($record instanceof Record) {
                    $records[] = $record;
                }
            }
            foreach ($records === [] ? [] : $this->eager as $relation) {
                $loaded = $relation($records[0]);
                if (!$loaded instanceof Relation) {
                    throw new InvalidArgumentException('A with() callback must return a relation of the record it receives.');
                }
                $loaded->eagerLoad($records);
            }
        }

        return $pairs;
    }

    /** @return T|null */
    public function first(): ?object
    {
        return $this->limit(1)->get()[0] ?? null;
    }

    /**
     * @return T
     *
     * @throws RecordNotFound
     */
    public function firstOrFail(): object
    {
        return $this->first() ?? throw new RecordNotFound($this->class, '(first match)');
    }

    /** @return T|null */
    public function find(int|string $id): ?object
    {
        return $this->using($this->builder->grouped()->where($this->qualifiedKey(), '=', $id))->first();
    }

    /**
     * @return T
     *
     * @throws RecordNotFound
     */
    public function findOrFail(int|string $id): object
    {
        return $this->find($id) ?? throw new RecordNotFound($this->class, $id);
    }

    public function count(): int
    {
        return $this->builder->count();
    }

    public function exists(): bool
    {
        return $this->builder->exists();
    }

    /**
     * Walks every matching record in key order, $chunkSize per query.
     *
     * @return Generator<int, T>
     */
    public function lazyById(int $chunkSize = 1000): Generator
    {
        if ($this->eager !== []) {
            throw new LogicException('lazyById() loads one record at a time; with() would query per record. Load relations per chunk instead.');
        }

        $key = RecordMetadata::of($this->class)->id->column;
        foreach ($this->builder->lazyById($chunkSize, $key) as $row) {
            yield $this->store->hydrate($this->class, self::row($row));
        }
    }

    /**
     * One page of records, with relations from with() loaded per page.
     *
     * @return Paginated<T>
     */
    public function paginate(Page $page, ?Ordering $ordering = null): Paginated
    {
        $query = $ordering === null ? $this : $this->using($ordering->applyTo($this->builder));

        return Paginated::of(
            $page,
            $this->count(),
            static fn (int $number): array => $query->using($query->builder->forPage($number, $page->perPage))->get(),
        );
    }

    /**
     * Updates every matching row. Loaded records are not changed.
     *
     * @param array<string, mixed> $values column => value
     */
    public function update(array $values): int
    {
        return $this->builder->update($values);
    }

    /** Deletes every matching row. Loaded records are not changed. */
    public function delete(): int
    {
        return $this->builder->delete();
    }

    /** The underlying query builder, for set-based work. */
    public function toBase(): Builder
    {
        return $this->builder;
    }

    /** @return self<T> */
    private function using(Builder $builder): self
    {
        return new self($this->store, $this->class, $builder, $this->eager);
    }

    private function qualifiedKey(): string
    {
        $meta = RecordMetadata::of($this->class);

        return $meta->table . '.' . $meta->id->column;
    }

    /** @return list<object> */
    private function rows(Builder $builder): array
    {
        return $builder->get();
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
}
