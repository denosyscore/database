<?php

declare(strict_types=1);

namespace Denosys\Database\Relations;

use Denosys\Database\Model;
use Denosys\Database\ModelBuilder;
use Denosys\Support\Collection;

class BelongsTo extends Relation
{
    /**
     * The foreign key of the parent model.
     */
    protected string $foreignKey;

    /**
     * The associated key on the parent model.
     */
    protected string $ownerKey;

    /**
     * Create a new belongs to instance.
     */
    public function __construct(ModelBuilder $query, Model $parent, string $foreignKey, string $ownerKey)
    {
        $this->foreignKey = $foreignKey;
        $this->ownerKey = $ownerKey;

        parent::__construct($query, $parent);
    }

    /**
     * {@inheritdoc}
     */
    public function addConstraints(): void
    {
        $this->query->where($this->ownerKey, '=', $this->parent->getAttribute($this->foreignKey));
    }

    /**
     * {@inheritdoc}
      * @param array<\Denosys\Database\Model> $models
     */
    public function addEagerConstraints(array $models): void
    {
        $this->query->whereIn(
            $this->ownerKey,
            $this->getEagerModelKeys($models)
        );
    }

    /**
     * Gather the keys from an array of models.
     */
    /**
     * @return array<string>
      * @param array<\Denosys\Database\Model> $models
     */
protected function getEagerModelKeys(array $models): array
    {
        return array_unique(array_values(array_map(function ($model) {
            return $model->getAttribute($this->foreignKey);
        }, $models)));
    }

    /**
     * {@inheritdoc}
      * @param array<\Denosys\Database\Model> $models
      * @return array<\Denosys\Database\Model>
     */
    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, null);
        }

        return $models;
    }

    /**
     * {@inheritdoc}
      * @param array<\Denosys\Database\Model> $models
      * @return array<\Denosys\Database\Model>
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            $dictionary[$result->getAttribute($this->ownerKey)] = $result;
        }

        foreach ($models as $model) {
            $key = $model->getAttribute($this->foreignKey);

            if (isset($dictionary[$key])) {
                $model->setRelation($relation, $dictionary[$key]);
            }
        }

        return $models;
    }

    /**
     * {@inheritdoc}
     */
    public function getResults(): ?Model
    {
        return $this->query->first();
    }
}
