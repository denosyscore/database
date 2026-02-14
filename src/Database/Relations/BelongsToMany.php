<?php

declare(strict_types=1);

namespace Denosys\Database\Relations;

use Denosys\Database\Model;
use Denosys\Database\ModelBuilder;
use Denosys\Support\Collection;

class BelongsToMany extends Relation
{
    /**
     * The intermediate table for the relation.
     */
    protected string $table;

    /**
     * The foreign key of the parent model.
     */
    protected string $foreignPivotKey;

    /**
     * The associated key of the relation.
     */
    protected string $relatedPivotKey;

    /**
     * The key name of the parent model.
     */
    protected string $parentKey;

    /**
     * The key name of the related model.
     */
    protected string $relatedKey;

    /**
     * Create a new belongs to many relationship instance.
     */
    public function __construct(
        ModelBuilder $query,
        Model $parent,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey
    ) {
        $this->table = $table;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->parentKey = $parentKey;
        $this->relatedKey = $relatedKey;

        parent::__construct($query, $parent);
    }

    /**
     * Set the base constraints on the relation query.
     */
    public function addConstraints(): void
    {
        $this->performJoin();

        if (static::$constraints) {
            $this->query->where(
                $this->table . '.' . $this->foreignPivotKey,
                '=',
                $this->parent->getAttribute($this->parentKey)
            );
        }
    }

    /**
     * Set the constraints for an eager load of the relation.
      * @param array<\Denosys\Database\Model> $models
     */
    public function addEagerConstraints(array $models): void
    {
        $this->performJoin();

        $this->query->whereIn(
            $this->table . '.' . $this->foreignPivotKey,
            $this->getKeys($models, $this->parentKey)
        );
    }

    /**
     * Initialize the relation on a set of models.
     *
     * @param array<\Denosys\Database\Model> $models
     * @return array<\Denosys\Database\Model>
     */
    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, new Collection());
        }

        return $models;
    }

    /**
     * Match the eagerly loaded results to their parents.
      * @param array<\Denosys\Database\Model> $models
      * @return array<\Denosys\Database\Model>
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        $dictionary = $this->buildDictionary($results);

        foreach ($models as $model) {
            $key = $model->getAttribute($this->parentKey);

            if (isset($dictionary[$key])) {
                $model->setRelation($relation, new Collection($dictionary[$key]));
            }
        }

        return $models;
    }

    /**
     * Build model dictionary keyed by the relation's foreign key.
     *
     * @return array<mixed, array<\Denosys\Database\Model>>
     */
    protected function buildDictionary(Collection $results): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            // We aliased the pivot key in performingJoin
            $key = $result->getAttribute('pivot_' . $this->foreignPivotKey);

            $dictionary[$key][] = $result;
        }

        return $dictionary;
    }

    /**
     * Perform the join clause for the query.
     */
    protected function performJoin(?ModelBuilder $query = null): void
    {
        $query = $query ?: $this->query;

        // We need to select the related table columns + the pivot key to match results
        $baseTable = $this->related->getTable();
        $keyName = $baseTable . '.' . $this->relatedKey;

        $query->join(
            $this->table,
            $keyName,
            '=',
            $this->table . '.' . $this->relatedPivotKey
        );

        // Select all from related table and the pivot foreign key so we can map it back
        $query->select([
            $baseTable . '.*', 
            $this->table . '.' . $this->foreignPivotKey . ' as pivot_' . $this->foreignPivotKey
        ]);
    }

    /**
     * Get the results of the relationship.
     */
    public function getResults(): mixed
    {
        return $this->query->get();
    }
}
