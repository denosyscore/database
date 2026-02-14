<?php

declare(strict_types=1);

namespace Denosys\Database\Migration;

use Denosys\Container\ContainerInterface;
use Denosys\Database\Connection\Connection;
use Denosys\Database\Schema\SchemaBuilder;
use Denosys\Database\Schema\Grammar\MySqlSchemaGrammar;
use Denosys\Database\Schema\Grammar\PostgresSchemaGrammar;
use Denosys\Database\Schema\Grammar\SqliteSchemaGrammar;
use Denosys\Database\Schema\Grammar\SchemaGrammarInterface;
use Denosys\Contracts\ServiceProviderInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Service provider for migration system.
 */
class MigrationServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // Register SchemaGrammar based on database driver
        $container->singleton(SchemaGrammarInterface::class, function (ContainerInterface $c) {
            $connection = $c->get(Connection::class);
            $driver = $connection->getDriverName();
            
            return match ($driver) {
                'mysql' => new MySqlSchemaGrammar(),
                'pgsql', 'postgres' => new PostgresSchemaGrammar(),
                'sqlite' => new SqliteSchemaGrammar(),
                default => new MySqlSchemaGrammar(),
            };
        });

        // Register SchemaBuilder
        $container->singleton(SchemaBuilder::class, function (ContainerInterface $c) {
            return new SchemaBuilder(
                $c->get(Connection::class),
                $c->get(SchemaGrammarInterface::class),
            );
        });

        // Register MigrationRepository
        $container->singleton(MigrationRepository::class, function (ContainerInterface $c) {
            return new MigrationRepository(
                $c->get(Connection::class),
                $c->get(SchemaBuilder::class),
            );
        });

        // Register Migrator
        $container->singleton(Migrator::class, function (ContainerInterface $c) {
            $basePath = $c->has('path.base') 
                ? $c->get('path.base') 
                : getcwd();
                
            return new Migrator(
                $c->get(Connection::class),
                $c->get(SchemaBuilder::class),
                $c->get(MigrationRepository::class),
                $basePath . '/database/migrations',
            );
        });
    }

    public function boot(ContainerInterface $container, ?EventDispatcherInterface $dispatcher = null): void
    {
        // Nothing to boot
    }
}
