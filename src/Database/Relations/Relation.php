<?php

declare(strict_types=1);

namespace Denosys\Database\Relations;

use Denosys\Database\Model;
use Denosys\Database\ModelBuilder;
use Denosys\Support\Collection;

/**
 * @mixin ModelBuilder
 */
abstract class Relation
{
    /**
     * The model builder instance.
     */
    protected ModelBuilder $query;

    /**
     * The parent model instance.
     */
    protected Model $parent;

    /**
     * The related model instance.
     */
    protected Model $related;

    /**
     * Indicates if the relation constraints should be initialized.
     */
    protected static bool $constraints = true;

    /**
     * Create a new relation instance.
     */
    public function __construct(ModelBuilder $query, Model $parent)
    {
        $this->query = $query;
        $this->parent = $parent;
        $this->related = $query->getModel();

        if (static::$constraints) {
            $this->addConstraints();
        }
    }

    /**
     * Run a callback with constraints disabled.
     */
    public static function noConstraints(callable $callback): mixed
    {
        $previous = static::$constraints;
        static::$constraints = false;

        try {
            return $callback();
        } finally {
            static::$constraints = $previous;
        }
    }

    /**
     * Set the base constraints on the relation query.
     */
    abstract public function addConstraints(): void;

    /**
     * Set the constraints for an eager load of the relation.
      * @param array<\Denosys\Database\Model> $models
     */
    abstract public function addEagerConstraints(array $models): void;

    /**
     * Initialize the relation on a set of models.
     *
     * @param array<\Denosys\Database\Model> $models
     * @return array<\Denosys\Database\Model>
     */
    abstract public function initRelation(array $models, string $relation): array;

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param array<\Denosys\Database\Model> $models
     * @return array<\Denosys\Database\Model>
     */
    abstract public function match(array $models, Collection $results, string $relation): array;

    /**
     * Get the results of the relationship.
     */
    abstract public function getResults(): mixed;

    /**
     * Get the underlying query for the relation.
     */
    public function getQuery(): ModelBuilder
    {
        return $this->query;
    }

    /**
     * Get the parent model of the relation.
     */
    public function getParent(): Model
    {
        return $this->parent;
    }

    /**
     * Get the related model of the relation.
     */
    public function getRelated(): Model
    {
        return $this->related;
    }

    /**
     * Get the keys for an array of models.
     *
     * @param array<\Denosys\Database\Model> $models
     * @return array<mixed>
     */
    protected function getKeys(array $models, string $key): array
    {
        return array_unique(array_values(array_map(function ($model) use ($key) {
            return $model->getAttribute($key);
        }, $models)));
    }

    /**
     * The name of the relation method.
     */
    protected string $relationName = '';

    /**
     * Set the relation name.
     */
    public function setRelationName(string $name): static
    {
        $this->relationName = $name;
        return $this;
    }

    /**
     * Eager load the relation for multiple models at once.
     * Used for auto-eager loading to batch load relations.
      * @param array<\Denosys\Database\Model> $models
     */
    public function eagerLoadRelations(array $models): void
    {
        // Get the relation name by finding which method on the parent returns this
        $methodName = $this->findRelationMethodName();
        
        // Add eager constraints for all models
        $this->addEagerConstraints($models);
        
        // Get results
        $results = $this->getQuery()->get();
        
        // Match results to parents
        $this->match($models, $results, $methodName);
    }

    /**
     * Find the method name that defines this relation.
     */
    protected function findRelationMethodName(): string
    {
        if (!empty($this->relationName)) {
            return $this->relationName;
        }

        // Fallback: inspect the backtrace to find the calling method
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        
        foreach ($trace as $frame) {
            if (isset($frame['function']) && 
                $frame['function'] !== 'eagerLoadRelations' &&
                $frame['function'] !== 'autoEagerLoadForSiblings' &&
                isset($frame['class']) && 
                is_a($frame['class'], Model::class, true)) {
                return $frame['function'];
            }
        }

        return 'unknown';
    }

    /**
     * Handle dynamic method calls to the relationship.
      * @param array<int, mixed> $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        $result = $this->query->$method(...$parameters);

        if ($result === $this->query) {
            return $this;
        }

        return $result;
    }
}
