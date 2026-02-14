<?php

declare(strict_types=1);

namespace Denosys\Database\Migration;

trait MigrationTrait
{
    /**
     * Get migration dependencies.
     * 
     * Override to specify migrations that must run before this one.
     * 
     * @return array<string, mixed>
     */
    public function dependsOn(): array
    {
        return [];
    }

    /**
     * Whether this migration should run within a transaction.
     */
    public function withinTransaction(): bool
    {
        return true;
    }

    /**
     * Get a description of what this migration does.
     */
    public function getDescription(): string
    {
        $class = static::class;
        
        if (str_contains($class, '@anonymous')) {
            return 'Anonymous migration';
        }
        
        // Get base class name
        $name = substr($class, strrpos($class, '\\') + 1);
        
        // Convert class name to readable description
        $name = preg_replace('/([A-Z])/', ' $1', $name);
        return trim($name);
    }
}
