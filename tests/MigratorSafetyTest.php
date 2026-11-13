<?php

declare(strict_types=1);

use Denosys\Database\Connection\Connection;
use Denosys\Database\Connection\ConnectionFactory;
use Denosys\Database\Migration\MigrationException;
use Denosys\Database\Migration\MigrationRepository;
use Denosys\Database\Migration\Migrator;
use Denosys\Database\Schema\Grammar\MySqlSchemaGrammar;
use Denosys\Database\Schema\Grammar\PostgresSchemaGrammar;
use Denosys\Database\Schema\Grammar\SqliteSchemaGrammar;
use Denosys\Database\Schema\SchemaBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FailingMigrationRepository extends MigrationRepository
{
    public bool $failLog = false;

    public bool $failDelete = false;

    public function log(string $migration, string $checksum, int $batch): void
    {
        parent::log($migration, $checksum, $batch);
        if ($this->failLog) {
            throw new RuntimeException('crash after the log row');
        }
    }

    public function delete(string $migration): void
    {
        parent::delete($migration);
        if ($this->failDelete) {
            throw new RuntimeException('crash after deleting the log row');
        }
    }
}

final class MigratorSafetyTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    /** @return iterable<string, array{string}> */
    public static function drivers(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        yield 'mysql' => ['mysql'];
        yield 'pgsql' => ['pgsql'];
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_dir($path)) {
                array_map('unlink', glob($path . '/*') ?: []);
                rmdir($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
    }

    #[DataProvider('drivers')]
    public function testAMigrationAndItsLogRowCommitTogether(string $driver): void
    {
        [$connection, $schema, $config] = $this->connect($driver);
        $repository = new FailingMigrationRepository($connection, $schema);
        $repository->failLog = true;
        $migrator = new Migrator($connection, $schema, $repository, $this->migrations(['0001_create_safety_widgets' => 'safety_widgets']));

        $result = $migrator->run();

        self::assertFalse($result->isSuccess());
        // The schema and the record always agree.
        self::assertSame($schema->hasTable('safety_widgets'), isset($repository->getRan()['0001_create_safety_widgets']));
        if ($driver !== 'mysql') {
            // Transactional DDL: the failure leaves neither behind. MySQL commits
            // DDL implicitly, so there both the table and its record remain.
            self::assertFalse($schema->hasTable('safety_widgets'));
        }
    }

    #[DataProvider('drivers')]
    public function testARollbackAndItsLogRowCommitTogether(string $driver): void
    {
        [$connection, $schema] = $this->connect($driver);
        $repository = new FailingMigrationRepository($connection, $schema);
        $migrator = new Migrator($connection, $schema, $repository, $this->migrations(['0001_create_safety_widgets' => 'safety_widgets']));
        self::assertTrue($migrator->run()->isSuccess());

        $repository->failDelete = true;
        self::assertFalse($migrator->rollback()->isSuccess());

        self::assertSame($schema->hasTable('safety_widgets'), isset($repository->getRan()['0001_create_safety_widgets']));
        if ($driver !== 'mysql') {
            // Transactional DDL: the table and its record are both kept. On
            // MySQL the implicit commit has already dropped and unrecorded it.
            self::assertTrue($schema->hasTable('safety_widgets'));
        }
    }

    #[DataProvider('drivers')]
    public function testOnlyOneRunHoldsTheLockAtATime(string $driver): void
    {
        [$connection, $schema, $config] = $this->connect($driver, file: true);
        $path = $this->migrations(['0001_create_safety_widgets' => 'safety_widgets']);

        $holder = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/hold-migration-lock.php', json_encode($config, JSON_THROW_ON_ERROR), '1.5'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($holder);
        $handshake = fgets($pipes[1]);
        if ($handshake !== "locked\n") {
            // Read stderr only now: it blocks until the child exits.
            self::fail('The lock holder did not start: ' . stream_get_contents($pipes[2]));
        }

        try {
            $impatient = new Migrator($connection, $schema, new MigrationRepository($connection, $schema), $path, lockTimeout: 0.3);
            try {
                $impatient->run();
                self::fail('ran while another process held the lock');
            } catch (MigrationException $e) {
                self::assertStringContainsString('holds the lock', $e->getMessage());
            }
            self::assertFalse($schema->hasTable('safety_widgets'));

            $patient = new Migrator($connection, $schema, new MigrationRepository($connection, $schema), $path, lockTimeout: 10.0);
            self::assertSame(1, $patient->run()->count());
        } finally {
            proc_close($holder);
        }

        self::assertTrue($schema->hasTable('safety_widgets'));
        self::assertSame(0, (new Migrator($connection, $schema, new MigrationRepository($connection, $schema), $path))->run()->count());
    }

    public function testAChangedMigrationBlocksTheRunUntilAccepted(): void
    {
        [$connection, $schema] = $this->connect('sqlite');
        $path = $this->migrations(['0001_create_safety_widgets' => 'safety_widgets']);
        $repository = new MigrationRepository($connection, $schema);
        (new Migrator($connection, $schema, $repository, $path))->run();

        file_put_contents($path . '/0001_create_safety_widgets.php', "\n// edited after it ran\n", FILE_APPEND);
        file_put_contents($path . '/0002_create_more_widgets.php', $this->migrationSource('more_widgets'));

        try {
            (new Migrator($connection, $schema, $repository, $path))->run();
            self::fail('ran with a changed migration');
        } catch (MigrationException $e) {
            self::assertStringContainsString('0001_create_safety_widgets', $e->getMessage());
        }
        self::assertFalse($schema->hasTable('more_widgets'));

        self::assertSame(1, (new Migrator($connection, $schema, $repository, $path))->acceptChanges()->run()->count());
        self::assertTrue($schema->hasTable('more_widgets'));
        self::assertSame(0, (new Migrator($connection, $schema, $repository, $path))->run()->count(), 'the new checksum was not recorded');
    }

    public function testPretendRecordsTheSqlAndChangesNothing(): void
    {
        [$connection, $schema] = $this->connect('sqlite');
        $migrator = (new Migrator($connection, $schema, new MigrationRepository($connection, $schema), $this->migrations(['0001_create_safety_widgets' => 'safety_widgets'])))->pretend();

        self::assertSame(1, $migrator->run()->count());

        $log = $migrator->getPretendLog();
        self::assertSame('-- 0001_create_safety_widgets', $log[0]);
        self::assertStringContainsString('CREATE TABLE "safety_widgets"', implode("\n", $log));
        self::assertFalse($schema->hasTable('safety_widgets'));
        self::assertFalse($schema->hasTable('schema_migrations'));
    }

    public function testPretendStopsAtAMigrationThatNeedsEarlierOnes(): void
    {
        [$connection, $schema] = $this->connect('sqlite');
        $path = $this->migrations(['0001_create_safety_widgets' => 'safety_widgets']);
        file_put_contents($path . '/0002_check_widgets.php', <<<'PHP'
            <?php
            use Denosys\Database\Migration\Migration;
            use Denosys\Database\Schema\SchemaBuilder;

            return new class extends Migration {
                public function up(SchemaBuilder $schema): void
                {
                    if (!$schema->hasTable('safety_widgets')) {
                        throw new RuntimeException('Expected safety_widgets.');
                    }
                }

                public function down(SchemaBuilder $schema): void
                {
                }
            };
            PHP);
        $migrator = (new Migrator($connection, $schema, new MigrationRepository($connection, $schema), $path))->pretend();

        $result = $migrator->run();

        self::assertFalse($result->isSuccess());
        self::assertSame('Preview stopped: Expected safety_widgets.', $result->getError());
        self::assertContains('-- 0001_create_safety_widgets', $migrator->getPretendLog());
        self::assertFalse($schema->hasTable('safety_widgets'));
    }

    public function testResetRevertsEveryBatchWhenTheNumberingHasAGap(): void
    {
        [$connection, $schema] = $this->connect('sqlite');
        $path = $this->migrations(['0001_create_safety_widgets' => 'safety_widgets']);
        $repository = new MigrationRepository($connection, $schema);
        (new Migrator($connection, $schema, $repository, $path))->run();
        file_put_contents($path . '/0002_create_more_widgets.php', $this->migrationSource('more_widgets'));
        (new Migrator($connection, $schema, $repository, $path))->run();
        $connection->update('UPDATE schema_migrations SET batch = 3 WHERE batch = 2');

        $result = (new Migrator($connection, $schema, $repository, $path))->reset();

        self::assertSame(2, $result->count());
        self::assertFalse($schema->hasTable('safety_widgets'));
        self::assertFalse($schema->hasTable('more_widgets'));
        self::assertSame([], $repository->getRan());
    }

    public function testAMigrationEditedAfterAPreviewRunsItsEditedCode(): void
    {
        [$connection, $schema] = $this->connect('sqlite');
        $path = $this->migrations(['0001_create_safety_widgets' => 'safety_widgets']);
        $migrator = new Migrator($connection, $schema, new MigrationRepository($connection, $schema), $path);
        $migrator->pretend()->run();

        file_put_contents($path . '/0001_create_safety_widgets.php', $this->migrationSource('more_widgets'));
        $migrator->pretend(false)->run();

        self::assertTrue($schema->hasTable('more_widgets'), 'the edited file ran');
        self::assertFalse($schema->hasTable('safety_widgets'), 'the cached object ran');
    }

    public function testMigrationsRunInFilenameOrderAcrossPaths(): void
    {
        [$connection, $schema] = $this->connect('sqlite');
        $app = $this->migrations(['2026_01_02_create_b' => 'b_widgets']);
        $package = $this->migrations(['2026_01_01_create_a' => 'a_widgets', '2026_01_03_create_c' => 'c_widgets']);

        $migrator = new Migrator($connection, $schema, new MigrationRepository($connection, $schema), $app);
        $migrator->addPath($package);

        self::assertSame(['2026_01_01_create_a', '2026_01_02_create_b', '2026_01_03_create_c'], array_keys($migrator->getPendingMigrations()));

        file_put_contents($package . '/2026_01_02_create_b.php', $this->migrationSource('b_widgets'));
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('exists in two paths');
        $migrator->getPendingMigrations();
    }

    /**
     * @return array{Connection, SchemaBuilder, array<string, mixed>}
     */
    private function connect(string $driver, bool $file = false): array
    {
        $config = match ($driver) {
            'sqlite' => ['driver' => 'sqlite', 'database' => $file ? $this->sqliteFile() : ':memory:'],
            'mysql' => $this->serverConfig('mysql', 'MYSQL', 3306, 'root'),
            'pgsql' => $this->serverConfig('pgsql', 'PGSQL', 5432, 'postgres'),
            default => throw new InvalidArgumentException($driver),
        };
        $connection = (new ConnectionFactory())->make($config);
        $schema = new SchemaBuilder($connection, match ($driver) {
            'sqlite' => new SqliteSchemaGrammar(),
            'mysql' => new MySqlSchemaGrammar(),
            default => new PostgresSchemaGrammar(),
        });
        foreach (['safety_widgets', 'more_widgets', 'a_widgets', 'b_widgets', 'c_widgets', 'schema_migrations'] as $table) {
            $schema->dropIfExists($table);
        }

        return [$connection, $schema, $config];
    }

    /**
     * @param array<string, string> $migrations file name => table it creates
     */
    private function migrations(array $migrations): string
    {
        $path = sys_get_temp_dir() . '/denosys-migrations-' . bin2hex(random_bytes(6));
        mkdir($path);
        $this->cleanup[] = $path;
        foreach ($migrations as $name => $table) {
            file_put_contents("{$path}/{$name}.php", $this->migrationSource($table));
        }

        return $path;
    }

    private function migrationSource(string $table): string
    {
        return <<<PHP
            <?php
            use Denosys\\Database\\Migration\\Migration;
            use Denosys\\Database\\Schema\\Blueprint;
            use Denosys\\Database\\Schema\\SchemaBuilder;

            return new class extends Migration {
                public function up(SchemaBuilder \$schema): void
                {
                    \$schema->create('{$table}', static function (Blueprint \$table): void {
                        \$table->id();
                        \$table->string('name');
                    });
                }

                public function down(SchemaBuilder \$schema): void
                {
                    \$schema->dropIfExists('{$table}');
                }
            };
            PHP;
    }

    private function sqliteFile(): string
    {
        $file = sys_get_temp_dir() . '/denosys-migrate-' . bin2hex(random_bytes(6)) . '.sqlite';
        touch($file);
        $this->cleanup[] = $file;
        $this->cleanup[] = $file . '.migrating';

        return $file;
    }

    /** @return array<string, mixed> */
    private function serverConfig(string $driver, string $prefix, int $port, string $user): array
    {
        $database = getenv("DENOSYS_TEST_{$prefix}_DATABASE");
        if (!$database) {
            self::markTestSkipped("Set DENOSYS_TEST_{$prefix}_DATABASE to run against a real {$driver} server.");
        }

        return [
            'driver' => $driver,
            'host' => getenv("DENOSYS_TEST_{$prefix}_HOST") ?: '127.0.0.1',
            'port' => (int) (getenv("DENOSYS_TEST_{$prefix}_PORT") ?: $port),
            'database' => $database,
            'username' => getenv("DENOSYS_TEST_{$prefix}_USERNAME") ?: $user,
            'password' => getenv("DENOSYS_TEST_{$prefix}_PASSWORD") ?: '',
        ];
    }
}
