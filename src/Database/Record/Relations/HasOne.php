<?php

declare(strict_types=1);

namespace Denosys\Database\Record\Relations;

use Closure;
use Denosys\Database\Exceptions\RelationNotLoaded;
use Denosys\Database\Record\Record;
use Denosys\Database\Record\RecordQuery;
use Denosys\Database\Record\Records;

/**
 * The parent's one record of another class, whose $foreignKey column holds
 * the parent's $localKey column. When several match, the first is used.
 *
 * @template-covariant P of Record
 * @template R of Record
 * @implements Relation<P, R>
 */
final readonly class HasOne implements Relation
{
    /**
     * @param P $parent
     * @param class-string<R> $related
     * @param (Closure(RecordQuery<R>): RecordQuery<R>)|null $constraint
     * @param list<Closure> $nested
     */
    public function __construct(
        private Record $parent,
        private string $related,
        private string $foreignKey,
        private string $localKey,
        private ?Closure $constraint = null,
        private array $nested = [],
    ) {
    }

    public function key(): string
    {
        return "hasOne:{$this->related}:{$this->foreignKey}:{$this->localKey}";
    }

    /**
     * Narrows or orders what the relation loads.
     *
     * @param Closure(RecordQuery<R>): RecordQuery<R> $constraint
     * @return self<P, R>
     */
    public function constrain(Closure $constraint): self
    {
        return new self($this->parent, $this->related, $this->foreignKey, $this->localKey, Matching::compose($this->constraint, $constraint), $this->nested);
    }

    /**
     * Loads relations of the related records too.
     *
     * @param Closure(R): Relation<Record, covariant Record> ...$relations
     * @return self<P, R>
     */
    public function with(Closure ...$relations): self
    {
        return new self($this->parent, $this->related, $this->foreignKey, $this->localKey, $this->constraint, [...$this->nested, ...array_values($relations)]);
    }

    public function isLoaded(): bool
    {
        return Records::store()->relation($this->parent, $this->key())[0];
    }

    /**
     * @return R|null
     *
     * @throws RelationNotLoaded
     */
    public function get(): ?Record
    {
        [$loaded, $value] = Records::store()->relation($this->parent, $this->key());
        if (!$loaded) {
            throw new RelationNotLoaded($this->parent::class, $this->key());
        }

        return $value instanceof $this->related ? $value : null;
    }

    public function query(): RecordQuery
    {
        $store = Records::store();

        return Matching::apply(
            $store->query($this->related)->where($this->foreignKey, '=', $store->columnValue($this->parent, $this->localKey)),
            $this->constraint,
            $this->nested,
        );
    }

    public function eagerLoad(array $parents): void
    {
        $store = Records::store();
        $values = Matching::values($store, $parents, $this->localKey);
        $groups = [];
        if ($values !== []) {
            $query = Matching::eager($store->query($this->related)->whereIn($this->foreignKey, $values), $this->constraint, $this->nested);
            foreach ($query->get() as $record) {
                $key = Matching::key($store->columnValue($record, $this->foreignKey));
                if ($key !== null) {
                    $groups[$key] ??= $record;
                }
            }
        }

        foreach ($parents as $parent) {
            $store->setRelation($parent, $this->key(), Matching::lookup($groups, $store->columnValue($parent, $this->localKey), null));
        }
    }
}
