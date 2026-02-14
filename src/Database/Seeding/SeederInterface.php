<?php

declare(strict_types=1);

namespace Denosys\Database\Seeding;

/**
 * Interface for database seeders.
 */
interface SeederInterface
{
    /**
     * Run the seeder.
     */
    public function run(): void;

    /**
     * Get seeder dependencies (seeders that must run before this one).
     * 
     * @return array<class-string<SeederInterface>>
     */
    public function getDependencies(): array;
}
