<?php

declare(strict_types=1);

namespace Denosys\Database\Record\Relations;

use Closure;
use Denosys\Database\Exceptions\RelationNotLoaded;
use Denosys\Database\Query\Builder;
use Denosys\Database\Record\Record;
use Denosys\Database\Record\RecordMetadata;
use Denosys\Database\Record\RecordQuery;
use Denosys\Database\Record\Records;

/**
 * Records of another class joined to the parent through a pivot table.
 * Pivot columns are kept apart from the related records: read them with
 * pivot() on an eager-loaded record.
 *
 * @template-covariant P of Record
 * @template R of Record
 * @implements Relation<P, R>
 */
final readonly class BelongsToMany implements Relation
{
    private const PARENT_ALIAS = '__pivot_parent';
    private const PIVOT_PREFIX = '__pivot_column_';

    /**
     * @param P $parent
     * @param class-string<R> $related
     * @param list<string> $pivotColumns
     * @param (Closure(RecordQuery<R>): RecordQuery<R>)|null $constraint
     * @param list<Closure> $nested
     */
    public function __construct(
        private Record $parent,
        private string $related,
        private string $pivotTable,
        private string $foreignPivotKey,
        private string $relatedPivotKey,
        private array $pivotColumns = [],
        private string $parentKey = 'id',
        private string $relatedKey = 'id',
        private ?Closure $constraint = null,
        private array $nested = [],
    ) {
    }

    public function key(): string
    {
        return "belongsToMany:{$this->related}:{$this->pivotTable}:{$this->foreignPivotKey}:{$this->relatedPivotKey}";
    }

    /**
     * @param Closure(RecordQuery<R>): RecordQuery<R> $constraint
     * @return self<P, R>
     */
    public function constrain(Closure $constraint): self
    {
        return $this->copy(Matching::compose($this->constraint, $constraint), $this->nested);
    }

    /**
     * @param Closure(R): Relation<Record, covariant Record> ...$relations
     * @return self<P, R>
     */
    public function with(Closure ...$relations): self
    {
        return $this->copy($this->constraint, [...$this->nested, ...array_values($relations)]);
    }

    public function isLoaded(): bool
    {
        return Records::store()->relation($this->parent, $this->key())[0];
    }

    /**
     * @return list<R>
     *
     * @throws RelationNotLoaded
     */
    public function get(): array
    {
        [$loaded, $value] = Records::store()->relation($this->parent, $this->key());
        if (!$loaded || !is_array($value)) {
            throw new RelationNotLoaded($this->parent::class, $this->key());
        }

        return array_values(array_filter($value, fn (mixed $record): bool => $record instanceof $this->related));
    }

    /**
     * The pivot columns of a record this relation loaded.
     *
     * @param R $record
     * @return array<string, mixed>
     *
     * @throws RelationNotLoaded when the record was not eager-loaded through this relation
     */
    public function pivot(Record $record): array
    {
        return Records::store()->pivot($record, $this->key()) ?? throw new RelationNotLoaded($record::class, $this->key() . ' pivot');
    }

    public function query(): RecordQuery
    {
        $store = Records::store();

        return Matching::apply(
            $this->joined()->where($this->pivotTable . '.' . $this->foreignPivotKey, '=', $store->columnValue($this->parent, $this->parentKey)),
            $this->constraint,
            $this->nested,
        );
    }

    public function eagerLoad(array $parents): void
    {
        $store = Records::store();
        $values = Matching::values($store, $parents, $this->parentKey);
        $groups = [];
        if ($values !== []) {
            $pivot = $this->pivotTable;
            $extra = [$pivot . '.' . $this->foreignPivotKey . ' as ' . self::PARENT_ALIAS];
            foreach ($this->pivotColumns as $column) {
                $extra[] = $pivot . '.' . $column . ' as ' . self::PIVOT_PREFIX . $column;
            }

            $query = Matching::eager(
                $this->joined()
                    ->tap(static fn (Builder $builder): Builder => $builder->addSelect($extra))
                    ->whereIn($pivot . '.' . $this->foreignPivotKey, $values),
                $this->constraint,
                $this->nested,
            );

            foreach ($query->getWithRows() as [$record, $row]) {
                $pivotValues = [];
                foreach ($this->pivotColumns as $column) {
                    $pivotValues[$column] = $row[self::PIVOT_PREFIX . $column] ?? null;
                }
                $store->setPivot($record, $this->key(), $pivotValues);
                $key = Matching::key($row[self::PARENT_ALIAS] ?? null);
                if ($key !== null) {
                    $groups[$key][] = $record;
                }
            }
        }

        foreach ($parents as $parent) {
            $store->setRelation($parent, $this->key(), Matching::lookup($groups, $store->columnValue($parent, $this->parentKey), []));
        }
    }

    /** @return RecordQuery<R> */
    private function joined(): RecordQuery
    {
        $table = RecordMetadata::of($this->related)->table;
        $pivot = $this->pivotTable;

        return Records::store()->query($this->related)->tap(
            fn (Builder $builder): Builder => $builder
                ->select([$table . '.*'])
                ->join($pivot, $pivot . '.' . $this->relatedPivotKey, '=', $table . '.' . $this->relatedKey),
        );
    }

    /**
     * @param (Closure(RecordQuery<R>): RecordQuery<R>)|null $constraint
     * @param list<Closure> $nested
     * @return self<P, R>
     */
    private function copy(?Closure $constraint, array $nested): self
    {
        return new self(
            $this->parent,
            $this->related,
            $this->pivotTable,
            $this->foreignPivotKey,
            $this->relatedPivotKey,
            $this->pivotColumns,
            $this->parentKey,
            $this->relatedKey,
            $constraint,
            $nested,
        );
    }
}
