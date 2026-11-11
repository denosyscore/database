<?php

declare(strict_types=1);

use Denosys\Database\Connection\Connection;
use Denosys\Database\Connection\ConnectionFactory;
use Denosys\Database\Grammar\PostgresGrammar;
use Denosys\Database\Schema\Blueprint;
use Denosys\Database\Schema\Grammar\MySqlSchemaGrammar;
use Denosys\Database\Schema\Grammar\PostgresSchemaGrammar;
use Denosys\Database\Schema\Grammar\SchemaGrammarInterface;
use Denosys\Database\Schema\Grammar\SqliteSchemaGrammar;
use Denosys\Database\Schema\SchemaBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaPortabilityTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function drivers(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        yield 'mysql' => ['mysql'];
        yield 'pgsql' => ['pgsql'];
    }

    public function testPostgresColumnsNeverCompileUnsigned(): void
    {
        $blueprint = new Blueprint('accounts');
        $blueprint->id();
        $blueprint->foreignId('owner_id');
        $blueprint->unsignedInteger('visits');

        $sql = implode("\n", (new PostgresSchemaGrammar())->compileCreate($blueprint));

        self::assertStringNotContainsString('UNSIGNED', $sql);
        self::assertStringContainsString('BIGSERIAL', $sql);
    }

    public function testPostgresTruncateDoesNotCascadeToReferencingTables(): void
    {
        $sql = (new PostgresGrammar())->compileTruncate('accounts');

        self::assertStringNotContainsString('CASCADE', $sql);
        self::assertStringContainsString('RESTART IDENTITY', $sql);
    }

    public function testDefaultIndexNamesIncludeTheTable(): void
    {
        $blueprint = new Blueprint('accounts');
        $blueprint->index('email');
        $blueprint->unique(['email', 'region']);
        $blueprint->dropIndex(['email']);
        $blueprint->dropUnique(['email', 'region']);

        self::assertSame(
            ['idx_accounts_email', 'uq_accounts_email_region'],
            array_map(static fn ($index) => $index->getName(), $blueprint->getIndexes()),
        );
        self::assertSame(['idx_accounts_email', 'uq_accounts_email_region'], $blueprint->getDropIndexes());
    }

    #[DataProvider('drivers')]
    public function testTwoTablesCanIndexAColumnWithTheSameName(string $driver): void
    {
        [$connection, $schema] = self::connect($driver);
        $schema->dropIfExists('portability_admins');
        $schema->dropIfExists('portability_users');

        $schema->create('portability_users', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->index('email');
            $table->string('handle')->unique();
        });
        $schema->create('portability_admins', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->index('email');
            $table->string('handle')->unique();
        });

        self::assertTrue($schema->hasTable('portability_admins'));

        $schema->drop('portability_admins');
        $schema->drop('portability_users');
    }

    #[DataProvider('drivers')]
    public function testIdColumnIsThePrimaryKey(string $driver): void
    {
        [, $schema] = self::connect($driver);
        $schema->dropIfExists('portability_keys');
        $schema->create('portability_keys', fn (Blueprint $table) => $table->id());

        $primary = array_values(array_filter($schema->getIndexes('portability_keys'), static fn (array $index) => $index['primary']));

        self::assertCount(1, $primary);
        self::assertSame(['id'], $primary[0]['columns']);
        $schema->drop('portability_keys');
    }

    #[DataProvider('drivers')]
    public function testForeignIdCanBeAddedToAnExistingTable(string $driver): void
    {
        if ($driver === 'sqlite') {
            self::markTestSkipped('Adding constraints to existing SQLite tables needs a table rebuild (phase 3).');
        }

        [$connection, $schema] = self::connect($driver);
        $schema->dropIfExists('portability_members');
        $schema->dropIfExists('portability_teams');
        $schema->create('portability_teams', fn (Blueprint $table) => $table->id());
        $schema->create('portability_members', fn (Blueprint $table) => $table->id());

        $schema->table('portability_members', function (Blueprint $table): void {
            $table->foreignId('team_id')->nullable();
            $table->foreign('team_id')->references('id')->on('portability_teams');
        });

        try {
            $connection->table('portability_members')->insert(['team_id' => 999]);
            self::fail('The foreign key was not enforced.');
        } catch (Throwable $exception) {
            self::assertStringContainsStringIgnoringCase('foreign', $exception->getMessage());
        } finally {
            $schema->drop('portability_members');
            $schema->drop('portability_teams');
        }
    }

    public function testMysqlDeleteWithLimitRemovesOnlyThatManyRows(): void
    {
        [$connection, $schema] = self::connect('mysql');
        $schema->dropIfExists('portability_rows');
        $schema->create('portability_rows', fn (Blueprint $table) => $table->id());
        for ($i = 0; $i < 5; $i++) {
            $connection->table('portability_rows')->insert(['id' => $i + 1]);
        }

        $deleted = $connection->table('portability_rows')->where('id', '>', 2)->orderBy('id', 'desc')->limit(1)->delete();

        self::assertSame(1, $deleted);
        self::assertSame([1, 2, 3, 4], array_map('intval', $connection->table('portability_rows')->orderBy('id')->pluck('id')));
        $schema->drop('portability_rows');
    }

    /** @return array{Connection, SchemaBuilder} */
    private static function connect(string $driver): array
    {
        $config = match ($driver) {
            'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'mysql' => self::serverConfig('mysql', 'MYSQL', 3306, 'root'),
            'pgsql' => self::serverConfig('pgsql', 'PGSQL', 5432, 'postgres'),
        };
        $grammar = match ($driver) {
            'sqlite' => new SqliteSchemaGrammar(),
            'mysql' => new MySqlSchemaGrammar(),
            'pgsql' => new PostgresSchemaGrammar(),
        };
        \assert($grammar instanceof SchemaGrammarInterface);
        $connection = (new ConnectionFactory())->make($config);

        return [$connection, new SchemaBuilder($connection, $grammar)];
    }

    /** @return array<string, mixed> */
    private static function serverConfig(string $driver, string $prefix, int $port, string $user): array
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
