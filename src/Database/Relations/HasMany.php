<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Relations;

use CFXP\Core\Database\Model;
use CFXP\Core\Database\ModelBuilder;
use CFXP\Core\Support\Collection;

class HasMany extends Relation
{
    /**
     * The foreign key of the parent model.
     */
    protected string $foreignKey;

    /**
     * The local key of the parent model.
     */
    protected string $localKey;

    /**
     * Create a new has many instance.
     */
    public function __construct(ModelBuilder $query, Model $parent, string $foreignKey, string $localKey)
    {
        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;

        parent::__construct($query, $parent);
    }

    /**
     * {@inheritdoc}
     */
    public function addConstraints(): void
    {
        $this->query->where($this->foreignKey, '=', $this->parent->getAttribute($this->localKey));
    }

    /**
     * {@inheritdoc}
      * @param array<\CFXP\Core\Database\Model> $models
     */
    public function addEagerConstraints(array $models): void
    {
        $this->query->whereIn(
            $this->foreignKey,
            $this->getKeys($models, $this->localKey)
        );
    }

    /**
     * {@inheritdoc}
     *
     * @param array<\CFXP\Core\Database\Model> $models
     * @return array<\CFXP\Core\Database\Model>
     */
    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, new Collection());
        }

        return $models;
    }

    /**
     * {@inheritdoc}
      * @param array<\CFXP\Core\Database\Model> $models
      * @return array<\CFXP\Core\Database\Model>
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        $dictionary = $this->buildDictionary($results);

        foreach ($models as $model) {
            $key = $model->getAttribute($this->localKey);

            if (isset($dictionary[$key])) {
                $model->setRelation($relation, new Collection($dictionary[$key]));
            }
        }

        return $models;
    }

    /**
     * Build model dictionary keyed by foreign key.
     *
     * @return array<mixed, array<\CFXP\Core\Database\Model>>
     */
    protected function buildDictionary(Collection $results): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            $key = $result->getAttribute($this->foreignKey);
            $dictionary[$key][] = $result;
        }

        return $dictionary;
    }

    /**
     * Get the keys for an array of models.
      * @param array<\CFXP\Core\Database\Model> $models
      * @return array<string>
     */
    protected function getKeys(array $models, string $key): array
    {
        return array_unique(array_values(array_map(function ($model) use ($key) {
            return $model->getAttribute($key);
        }, $models)));
    }

    /**
     * {@inheritdoc}
     */
    public function getResults(): Collection
    {
        return $this->query->get();
    }
}
