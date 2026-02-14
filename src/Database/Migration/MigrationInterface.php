<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Migration;

use CFXP\Core\Database\Schema\SchemaBuilder;

/**
 * Contract for database migrations.
 * 
 * Migrations define schema changes that can be applied (up) and reverted (down).
 */
interface MigrationInterface
{
    /**
     * Apply the migration.
     */
    public function up(SchemaBuilder $schema): void;

    /**
     * Revert the migration.
     */
    public function down(SchemaBuilder $schema): void;

    /**
     * Get migration dependencies.
     * 
     * Return an array of migration class names that must run before this one.
     * This enables dependency-based ordering instead of just timestamp ordering.
     * 
     * @return array<class-string<MigrationInterface>>
     */
    public function dependsOn(): array;

    /**
     * Whether this migration should run within a transaction.
     * 
     * Some operations (like ALTER TABLE in MySQL with certain engines) 
     * may not support transactions. Override to return false if needed.
     */
    public function withinTransaction(): bool;

    /**
     * Get a description of what this migration does.
     * 
     * Used for display in status commands.
     */
    public function getDescription(): string;
}
