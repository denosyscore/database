<?php

declare(strict_types=1);

namespace Denosys\Database\Connection;

use PDO;
use Denosys\Database\Contracts\GrammarInterface;
use Denosys\Database\Grammar\MySqlGrammar;
use Denosys\Database\Grammar\PostgresGrammar;
use Denosys\Database\Grammar\SqliteGrammar;
use Denosys\Database\Exceptions\DatabaseException;

class ConnectionFactory
{
    /**
     * Mapping of driver names to grammar classes.
      * @var array<string, class-string<\Denosys\Database\Contracts\GrammarInterface>>
     */
    protected array $grammars = [
        'mysql' => MySqlGrammar::class,
        'pgsql' => PostgresGrammar::class,
        'sqlite' => SqliteGrammar::class,
    ];

    /**
     * Create a new connection factory instance.
     */
    public function __construct()
    {
    }

    /**
     * Create a new database connection.
     *
     * @param array<string, mixed> $config Connection configuration
     * @return Connection
     * @throws DatabaseException
     */
    public function make(array $config): Connection
    {
        $driver = $config['driver'] ?? 'mysql';

        if (!isset($this->grammars[$driver])) {
            throw new DatabaseException("Unsupported database driver: {$driver}");
        }

        $pdo = $this->createPdoConnection($driver, $config);
        $grammar = $this->createGrammar($driver);

        return new Connection($pdo, $grammar, $config);
    }

    /**
     * Create a PDO connection based on driver.
      * @param array<string, mixed> $config
     */
    protected function createPdoConnection(string $driver, array $config): PDO
    {
        return match ($driver) {
            'mysql' => $this->createMySqlConnection($config),
            'pgsql' => $this->createPostgresConnection($config),
            'sqlite' => $this->createSqliteConnection($config),
            default => throw new DatabaseException("Unsupported driver: {$driver}"),
        };
    }

    /**
     * Create a MySQL PDO connection.
      * @param array<string, mixed> $config
     */
    protected function createMySqlConnection(array $config): PDO
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 3306;
        $database = $config['database'] ?? '';
        $username = $config['username'] ?? 'root';
        $password = $config['password'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';
        $collation = $config['collation'] ?? 'utf8mb4_unicode_ci';

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

        $options = $this->getDefaultOptions($config);
        $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES '{$charset}' COLLATE '{$collation}'";

        $options = array_merge($options, $this->getMySqlSslOptions($config));

        if (isset($config['unix_socket']) && !empty($config['unix_socket'])) {
            $dsn = "mysql:unix_socket={$config['unix_socket']};dbname={$database};charset={$charset}";
        }

        try {
            return new PDO($dsn, $username, $password, $options);
        } catch (\PDOException $e) {
            throw new DatabaseException("MySQL connection failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get MySQL SSL/TLS PDO options from config.
     */
    /**
     * @return array<string, mixed>
      * @param array<string, mixed> $config
     */
protected function getMySqlSslOptions(array $config): array
    {
        $options = [];

        if (!isset($config['ssl'])) {
            return $options;
        }

        $ssl = $config['ssl'];

        if ($ssl === true) {
            // Simple SSL: enable with server cert verification
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        } elseif (is_array($ssl)) {
            // Detailed SSL config
            if (isset($ssl['ca'])) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $ssl['ca'];
            }
            if (isset($ssl['cert'])) {
                $options[PDO::MYSQL_ATTR_SSL_CERT] = $ssl['cert'];
            }
            if (isset($ssl['key'])) {
                $options[PDO::MYSQL_ATTR_SSL_KEY] = $ssl['key'];
            }
            if (isset($ssl['capath'])) {
                $options[PDO::MYSQL_ATTR_SSL_CAPATH] = $ssl['capath'];
            }
            if (isset($ssl['cipher'])) {
                $options[PDO::MYSQL_ATTR_SSL_CIPHER] = $ssl['cipher'];
            }
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $ssl['verify'] ?? true;
        }

        return $options;
    }

    /**
     * Create a PostgreSQL PDO connection.
      * @param array<string, mixed> $config
     */
    protected function createPostgresConnection(array $config): PDO
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 5432;
        $database = $config['database'] ?? '';
        $username = $config['username'] ?? 'postgres';
        $password = $config['password'] ?? '';
        $schema = $config['schema'] ?? 'public';

        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";

        if (isset($config['sslmode'])) {
            $dsn .= ";sslmode={$config['sslmode']}";
        }

        $options = $this->getDefaultOptions($config);

        try {
            $pdo = new PDO($dsn, $username, $password, $options);
            
            // Set the search path (schema)
            $pdo->exec("SET search_path TO {$schema}");
            
            // Set timezone if configured
            if (isset($config['timezone'])) {
                $pdo->exec("SET timezone TO '{$config['timezone']}'");
            }

            return $pdo;
        } catch (\PDOException $e) {
            throw new DatabaseException("PostgreSQL connection failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Create a SQLite PDO connection.
      * @param array<string, mixed> $config
     */
    protected function createSqliteConnection(array $config): PDO
    {
        $database = $config['database'] ?? ':memory:';

        // Support both file paths and :memory:
        if ($database !== ':memory:' && !file_exists($database)) {
            // Create the database file if it doesn't exist
            $directory = dirname($database);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            touch($database);
        }

        $dsn = "sqlite:{$database}";

        $options = $this->getDefaultOptions($config);

        try {
            $pdo = new PDO($dsn, null, null, $options);
            
            // Enable foreign keys (disabled by default in SQLite)
            $pdo->exec('PRAGMA foreign_keys = ON');

            // Set journal mode to WAL for better concurrency
            if (isset($config['journal_mode'])) {
                $pdo->exec("PRAGMA journal_mode = {$config['journal_mode']}");
            }

            // Set synchronous mode
            if (isset($config['synchronous'])) {
                $pdo->exec("PRAGMA synchronous = {$config['synchronous']}");
            }

            return $pdo;
        } catch (\PDOException $e) {
            throw new DatabaseException("SQLite connection failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get default PDO options.
     *
     * @param array<string, mixed> $config
     * @return array<int, mixed>
     */
    protected function getDefaultOptions(array $config): array
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // Merge with custom options from config
        if (isset($config['options']) && is_array($config['options'])) {
            $options = array_merge($options, $config['options']);
        }

        return $options;
    }

    /**
     * Create a grammar instance for the given driver.
     */
    protected function createGrammar(string $driver): GrammarInterface
    {
        $grammarClass = $this->grammars[$driver];

        return new $grammarClass();
    }

    /**
     * Register a custom grammar for a driver.
     *
     * @param string $driver
     * @param string $grammarClass Fully qualified class name
     */
    public function registerGrammar(string $driver, string $grammarClass): void
    {
        $this->grammars[$driver] = $grammarClass;
    }

    /**
     * Check if a driver is supported.
     */
    public function isDriverSupported(string $driver): bool
    {
        return isset($this->grammars[$driver]);
    }

    /**
     * Get list of supported drivers.
     *
     * @return array<string>
     */
    public function getSupportedDrivers(): array
    {
        return array_keys($this->grammars);
    }
}
