<?php

declare(strict_types=1);

namespace Denosys\Database\Seeding;

/**
 * Executes seeders with dependency resolution.
 * 
 * Uses topological sort to ensure seeders run in correct order
 * based on their declared dependencies.
 */
class SeederRunner
{
    /**
     * Seeder classes that have been executed.
     * 
     * @var array<class-string<SeederInterface>, true>
     */
    /** @var array<string, mixed> */

    private array $executed = [];

    /**
     * Currently resolving seeders (for circular dependency detection).
     * 
     * @var array<class-string<SeederInterface>, true>
     */
    /** @var array<string, mixed> */

    private array $resolving = [];

    /**
     * Run a single seeder (and its dependencies).
     * 
     * @param class-string<SeederInterface> $seederClass
     */
    public function run(string $seederClass): void
    {
        $this->resolve($seederClass);
    }

    /**
     * Run multiple seeders (and their dependencies).
     * 
     * @param array<class-string<SeederInterface>> $seederClasses
      * @param array<string, mixed> $seederClasses
     */
    public function runAll(array $seederClasses): void
    {
        foreach ($seederClasses as $seederClass) {
            $this->resolve($seederClass);
        }
    }

    /**
     * Resolve dependencies and run seeder.
     * 
     * @param class-string<SeederInterface> $seederClass
     */
    private function resolve(string $seederClass): void
    {
        // Already executed, skip
        if (isset($this->executed[$seederClass])) {
            return;
        }

        // Circular dependency detection
        if (isset($this->resolving[$seederClass])) {
            throw new \RuntimeException(
                "Circular dependency detected: {$seederClass} depends on itself (directly or indirectly)"
            );
        }

        $this->resolving[$seederClass] = true;

        // Create seeder instance
        $seeder = $this->createSeeder($seederClass);

        // Resolve dependencies first
        foreach ($seeder->getDependencies() as $dependency) {
            $this->resolve($dependency);
        }

        // Run this seeder
        $seeder->run();

        // Mark as executed
        $this->executed[$seederClass] = true;
        unset($this->resolving[$seederClass]);
    }

    /**
     * Create a seeder instance.
     * 
     * @param class-string<SeederInterface> $seederClass
     */
    private function createSeeder(string $seederClass): SeederInterface
    {
        if (!class_exists($seederClass)) {
            throw new \RuntimeException("Seeder class not found: {$seederClass}");
        }

        $seeder = new $seederClass();

        if (!$seeder instanceof SeederInterface) {
            throw new \RuntimeException(
                "Seeder must implement SeederInterface: {$seederClass}"
            );
        }

        return $seeder;
    }

    /**
     * Reset the executed tracking (for testing).
     */
    public function reset(): void
    {
        $this->executed = [];
        $this->resolving = [];
    }

    /**
     * Get list of executed seeder classes.
     * 
     * @return array<class-string<SeederInterface>>
     */
    /**
     * @return array<string, mixed>
     */
public function getExecuted(): array
    {
        return array_keys($this->executed);
    }
}
