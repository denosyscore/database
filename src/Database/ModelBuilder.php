<?php

declare(strict_types=1);

namespace Denosys\Database;

use Denosys\Database\Pagination\LengthAwarePaginator;
use Denosys\Database\Query\Builder;
use Denosys\Support\Collection;
use InvalidArgumentException;

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

    public function paginate(int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        if ($page < 1 || $perPage < 1) {
            throw new InvalidArgumentException('Page and per-page values must be positive.');
        }

        $countQuery = clone $this;
        $countQuery->limitValue = null;
        $countQuery->offsetValue = null;
        $countQuery->orders = [];
        $countQuery->bindings['order'] = [];
        $total = $countQuery->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $currentPage = min($page, $lastPage);

        $items = (clone $this)->forPage($currentPage, $perPage)->get();

        return new LengthAwarePaginator(
            $items instanceof Collection ? $items : new Collection($items),
            $total,
            $currentPage,
            $perPage,
        );
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
     * @return array<object>|\Denosys\Support\Collection<\Denosys\Database\Model>
     */
    public function get(): array|\Denosys\Support\Collection
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

        return new \Denosys\Support\Collection($models);
    }

    /**
     * Eager load the relationships for the models.
     *
     * @param array<\Denosys\Database\Model> $models
     * @return array<\Denosys\Database\Model>
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
