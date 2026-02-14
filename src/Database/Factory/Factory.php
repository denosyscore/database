<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Factory;

use Faker\Factory as FakerFactory;
use Faker\Generator as Faker;

/**
 * @phpstan-consistent-constructor
 */
abstract class Factory implements FactoryInterface
{
    /**
     * The namespace where models are located.
     * Override globally via Factory::useModelNamespace()
     */
    protected static string $modelNamespace = 'App\\Models';

    /**
     * Faker instance for generating random data.
     */
    public readonly Faker $faker;

    /**
     * The model class this factory creates.
     * Override to explicitly set the model class.
     */
    protected ?string $model = null;

    /**
     * State modifications to apply.
     * 
     * @var array<string, mixed>
     */
    /** @var array<string, mixed> */

    protected array $states = [];

    /**
     * Sequence of states to cycle through.
     * 
     * @var array<array<string, mixed>>
     */
    /** @var array<string, mixed> */

    protected array $sequence = [];

    /**
     * Current sequence index.
     */
    protected int $sequenceIndex = 0;

    public function __construct(?Faker $faker = null)
    {
        $this->faker = $faker ?? FakerFactory::create();
    }

    /**
     * Set the default model namespace for inference.
     * 
     * Call this in your AppServiceProvider or bootstrap:
     * Factory::useModelNamespace('MyApp\\Domain\\Models');
     */
    public static function useModelNamespace(string $namespace): void
    {
        static::$modelNamespace = rtrim($namespace, '\\');
    }

    /**
     * Get the current model namespace.
     */
    public static function getModelNamespace(): string
    {
        return static::$modelNamespace;
    }

    /**
     * Create a new factory instance.
     */
    public static function new(?Faker $faker = null): static
    {
        return new static($faker);
    }

    /**
     * Get the model class this factory creates.
     * 
     * Override this method or set the $model property for explicit control.
     * By default, infers from factory name using the configured model namespace.
     */
    public function model(): string
    {
        if ($this->model !== null) {
            return $this->model;
        }

        return $this->inferModelClass();
    }

    /**
     * Infer model class from factory class name.
     * 
     * Convention: UserFactory -> {modelNamespace}\User
     */
    protected function inferModelClass(): string
    {
        $factoryClass = static::class;
        $factoryName = $this->getBaseName($factoryClass);
        
        // Remove "Factory" suffix
        $modelName = preg_replace('/Factory$/', '', $factoryName);
        
        if (empty($modelName)) {
            throw new \RuntimeException(
                "Cannot infer model from factory [{$factoryClass}]. " .
                "Set the \$model property or override model() method."
            );
        }

        return static::$modelNamespace . '\\' . $modelName;
    }

    /**
     * Get the base class name without namespace.
     */
    private function getBaseName(string $class): string
    {
        $parts = explode('\\', $class);
        return end($parts);
    }

    /**
     * Define the default attribute values.
     * 
     * @return array<string, mixed>
     */
    abstract public function definition(): array;

    /**
     * {@inheritdoc}
      * @param array<string, mixed> $attributes
     */
    public function make(int $count = 1, array $attributes = []): object|array
    {
        if ($count === 1) {
            return $this->makeOne($attributes);
        }

        $models = [];
        for ($i = 0; $i < $count; $i++) {
            $models[] = $this->makeOne($attributes);
        }

        return $models;
    }

    /**
     * {@inheritdoc}
      * @param array<string, mixed> $attributes
     */
    public function create(int $count = 1, array $attributes = []): object|array
    {
        if ($count === 1) {
            return $this->createOne($attributes);
        }

        $models = [];
        for ($i = 0; $i < $count; $i++) {
            $models[] = $this->createOne($attributes);
        }

        return $models;
    }

    /**
     * {@inheritdoc}
      * @param array<string, mixed> $state
     */
    public function state(array $state): static
    {
        $clone = clone $this;
        $clone->states = array_merge($clone->states, $state);
        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function sequence(array ...$sequence): static
    {
        $clone = clone $this;
        $clone->sequence = $sequence;
        $clone->sequenceIndex = 0;
        return $clone;
    }

    /**
     * Create a single model instance without persisting.
     * 
     * @param array<string, mixed> $attributes
     */
    protected function makeOne(array $attributes = []): object
    {
        $modelClass = $this->model();
        $finalAttributes = $this->resolveAttributes($attributes);
        
        return new $modelClass($finalAttributes);
    }

    /**
     * Create and persist a single model instance.
     * 
     * @param array<string, mixed> $attributes
     */
    protected function createOne(array $attributes = []): object
    {
        $model = $this->makeOne($attributes);
        
        if (method_exists($model, 'save')) {
            $model->save();
        }
        
        return $model;
    }

    /**
     * Resolve final attributes by merging definition, states, sequence, and overrides.
     * 
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
      * @param array<string, mixed> $attributes
     */
protected function resolveAttributes(array $attributes): array
    {
        // Start with definition (evaluate closures)
        $resolved = $this->evaluateAttributes($this->definition());
        
        // Apply accumulated states
        $resolved = array_merge($resolved, $this->states);
        
        // Apply sequence if set
        if (!empty($this->sequence)) {
            $sequenceState = $this->sequence[$this->sequenceIndex % count($this->sequence)];
            $resolved = array_merge($resolved, $sequenceState);
            $this->sequenceIndex++;
        }
        
        // Apply inline overrides last
        $resolved = array_merge($resolved, $attributes);
        
        return $resolved;
    }

    /**
     * Evaluate attribute values, resolving closures.
     * 
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function evaluateAttributes(array $attributes): array
    {
        $evaluated = [];
        
        foreach ($attributes as $key => $value) {
            if ($value instanceof \Closure) {
                $evaluated[$key] = $value($this->faker);
            } else {
                $evaluated[$key] = $value;
            }
        }
        
        return $evaluated;
    }
}
