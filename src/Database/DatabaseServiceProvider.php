<?php

declare(strict_types=1);

namespace Denosys\Database;

use Denosys\Container\ContainerInterface;
use Denosys\Contracts\ServiceProviderInterface;
use Denosys\Config\ConfigurationInterface;
use Denosys\Database\Connection\Connection;
use Psr\EventDispatcher\EventDispatcherInterface;
use Denosys\Database\Connection\ConnectionFactory;
use Denosys\Database\Connection\ConnectionManager;

class DatabaseServiceProvider implements ServiceProviderInterface
{
    /**
     * Register database services.
     */
    public function register(ContainerInterface $container): void
    {
        // Register the connection factory
        $container->singleton(ConnectionFactory::class, function () {
            return new ConnectionFactory();
        });

        // Register the connection manager
        $container->singleton(ConnectionManager::class, function ($container) {
            $factory = $container->get(ConnectionFactory::class);
            $manager = new ConnectionManager($factory);

            // Load database configuration
            $config = $container->get(ConfigurationInterface::class);
            $databaseConfig = $config->get('database', []);

            // Set default connection
            $defaultConnection = $databaseConfig['default'] ?? 'mysql';
            $manager->setDefaultConnection($defaultConnection);

            // Register all configured connections
            $connections = $databaseConfig['connections'] ?? [];
            foreach ($connections as $name => $connectionConfig) {
                $manager->addConnection($name, $connectionConfig);
            }

            return $manager;
        });

        // Register a convenient 'db' alias for the default connection
        $container->singleton('db', function ($container) {
            return $container->get(ConnectionManager::class)->connection();
        });

        // Register Connection interface binding
        $container->singleton(Connection::class, function ($container) {
            return $container->get(ConnectionManager::class)->connection();
        });
    }

    /**
     * Boot database services.
     */
    public function boot(ContainerInterface $container, ?EventDispatcherInterface $dispatcher = null): void
    {
        // Set the connection manager on the Model class for Active Record pattern
        $manager = $container->get(ConnectionManager::class);
        Model::setConnectionManager($manager);

        // Initialize DB facade
        Database::setConnectionManager($manager);
    }
}
