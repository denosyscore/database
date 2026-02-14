<?php

declare(strict_types=1);

namespace Denosys\Database\Factory;

/**
 * Trait for models to enable factory access.
 * 
 * @example
 * class User extends Model {
 *     use HasFactory;
 * }
 * 
 * // Usage
 * User::factory()->create(10);
 */
trait HasFactory
{
    /**
     * Get a new factory instance for the model.
     * 
     * @return FactoryInterface
     */
    public static function factory(): FactoryInterface
    {
        $factoryClass = static::resolveFactoryClass();
        
        if (!class_exists($factoryClass)) {
            throw new \RuntimeException(
                "Factory [{$factoryClass}] not found for model [" . static::class . "]"
            );
        }
        
        return $factoryClass::new();
    }

    /**
     * Resolve the factory class name for this model.
     */
    protected static function resolveFactoryClass(): string
    {
        // Convention: App\Models\User -> Database\Factories\UserFactory
        $modelClass = static::class;
        $modelName = class_basename($modelClass);
        
        return "Database\\Factories\\{$modelName}Factory";
    }
}

/**
 * Get the class basename.
 */
function class_basename(string $class): string
{
    $parts = explode('\\', $class);
    return end($parts);
}
