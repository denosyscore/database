<?php

declare(strict_types=1);

namespace CFXP\Core\Database;

use CFXP\Core\Database\Query\Builder;

class ModelBuilder extends Builder
{
    /**
     * The model being queried.
     */
    protected ?Model $model = null;

    /**
     * Set the model instance for the builder.
     */
    public function setModel(Model $model): static
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Get the model instance being queried.
     */
    public function getModel(): ?Model
    {
        return $this->model;
    }

    /**
     * The relationships that should be eager loaded.
     */
    /** @var array<string> */
    protected array $eagerLoad = [];

    /**
     * Set the relationships that should be eager loaded.
      * @param array<string, mixed> $relations
     */
    public function with(string|array $relations): static
    {
        if (is_string($relations)) {
            $relations = func_get_args();
        }

        $this->eagerLoad = array_merge($this->eagerLoad, $relations);

        return $this;
    }

    /**
     * Find a model by its primary key.
     *
     * @return Model|null
     */
    public function find(int|string $id, string $primaryKey = 'id'): ?Model
    {
        if ($this->model === null) {
            return parent::find($id, $primaryKey);
        }

        // get() already returns hydrated Model instances via the overridden method
        $results = $this->where($this->model->getKeyName(), '=', $id)->limit(1)->get();

        if ($results->isEmpty()) {
            return null;
        }

        return $results->first();
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @return array<object>|\CFXP\Core\Support\Collection<\CFXP\Core\Database\Model>
     */
    public function get(): array|\CFXP\Core\Support\Collection
    {
        $results = parent::get();

        if ($this->model === null) {
            return $results;
        }

        $models = [];
        
        // Generate a unique collection ID for auto-eager loading
        $collectionId = count($results) > 1 ? uniqid('collection_', true) : null;
        
        foreach ($results as $result) {
            $attributes = (array) $result;
            $model = $this->model->newFromDatabase($attributes);
            
            // Register model in collection for auto-eager loading
            if ($collectionId !== null) {
                $model->registerInCollection($collectionId);
            }
            
            $models[] = $model;
        }

        if (!empty($models) && !empty($this->eagerLoad)) {
            $models = $this->eagerLoadRelations($models);
        }

        return new \CFXP\Core\Support\Collection($models);
    }

    /**
     * Eager load the relationships for the models.
     *
     * @param array<\CFXP\Core\Database\Model> $models
     * @return array<\CFXP\Core\Database\Model>
     */
    protected function eagerLoadRelations(array $models): array
    {
        foreach ($this->eagerLoad as $name) {
            if (!method_exists($this->model, $name)) {
                continue;
            }

            // Get relation instance with constraints disabled
            $relation = Relations\Relation::noConstraints(function () use ($name) {
                return $this->model->$name();
            });

            if (!$relation instanceof Relations\Relation) {
                continue;
            }

            // Load the results for the relation
            $relation->addEagerConstraints($models);
            
            $results = $relation->getQuery()->get(); // This returns a Collection

            // Match results to parents
            $models = $relation->match($models, $results, $name);
        }

        return $models;
    }
}
