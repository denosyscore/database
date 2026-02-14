<?php

declare(strict_types=1);

namespace Denosys\Database\Seeding;

/**
 * Abstract base class for database seeders.
 */
abstract class Seeder implements SeederInterface
{
    /**
     * {@inheritdoc}
     */
    abstract public function run(): void;

    /**
     * {@inheritdoc}
     */
    /**
     * @return array<string, mixed>
     */
public function getDependencies(): array
    {
        return [];
    }
}
