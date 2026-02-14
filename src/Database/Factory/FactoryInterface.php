<?php

declare(strict_types=1);

namespace Denosys\Database\Factory;

interface FactoryInterface
{
    /**
     * Get the model class this factory creates.
     * May be inferred from factory name or explicitly set.
     */
    public function model(): string;

    /**
     * Define the default attribute values.
     * 
     * @return array<string, mixed>
     */
    public function definition(): array;

    /**
     * Create model instance(s) without persisting to database.
     * 
     * @param int $count Number of instances to create
     * @param array<string, mixed> $attributes Override attributes
     * @return object|array<object> Single instance or array of instances
     */
    public function make(int $count = 1, array $attributes = []): object|array;

    /**
     * Create and persist model instance(s) to database.
     * 
     * @param int $count Number of instances to create
     * @param array<string, mixed> $attributes Override attributes
     * @return object|array<object> Single instance or array of instances
     */
    public function create(int $count = 1, array $attributes = []): object|array;

    /**
     * Apply state modifications.
     * 
     * @param array<string, mixed> $state Attributes to merge
     */
    public function state(array $state): static;

    /**
     * Apply a sequence of states to cycle through.
     * 
     * @param array<string, mixed> ...$sequence States to cycle through
     */
    public function sequence(array ...$sequence): static;
}
