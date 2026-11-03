<?php

declare(strict_types=1);

use Denosys\Database\Connection\ConnectionFactory;
use Denosys\Database\Schema\Grammar\MySqlSchemaGrammar;
use Denosys\Database\Schema\Grammar\PostgresSchemaGrammar;
use Denosys\Database\Schema\SchemaBuilder;
use PHPUnit\Framework\TestCase;

final class SqlIndexMetadataTest extends TestCase
{
    public function testMySqlIndexesReportNamesOrderedColumnsAndUniqueness(): void
    {
        $database = getenv('DENOSYS_TEST_MYSQL_DATABASE');
        if (!$database) {
            self::markTestSkipped('Set DENOSYS_TEST_MYSQL_DATABASE for the MySQL index integration test.');
        }

        $connection = (new ConnectionFactory())->make([
            'driver' => 'mysql',
            'host' => getenv('DENOSYS_TEST_MYSQL_HOST') ?: '127.0.0.1',
            'port' => getenv('DENOSYS_TEST_MYSQL_PORT') ?: 3306,
            'database' => $database,
            'username' => getenv('DENOSYS_TEST_MYSQL_USERNAME') ?: 'root',
            'password' => getenv('DENOSYS_TEST_MYSQL_PASSWORD') ?: '',
        ]);
        $connection->statement('CREATE TEMPORARY TABLE index_metadata_fixture (
            id BIGINT PRIMARY KEY,
            email VARCHAR(191) NOT NULL,
            region VARCHAR(20) NOT NULL,
            UNIQUE KEY uq_email (email),
            KEY idx_region (region),
            UNIQUE KEY uq_email_region (email, region)
        )');

        $schema = new SchemaBuilder($connection, new MySqlSchemaGrammar());
        self::assertSame([
            ['name' => 'PRIMARY', 'columns' => ['id'], 'unique' => true, 'primary' => true],
            ['name' => 'idx_region', 'columns' => ['region'], 'unique' => false, 'primary' => false],
            ['name' => 'uq_email', 'columns' => ['email'], 'unique' => true, 'primary' => false],
            ['name' => 'uq_email_region', 'columns' => ['email', 'region'], 'unique' => true, 'primary' => false],
        ], $schema->getIndexes('index_metadata_fixture'));
        self::assertSame([], $schema->getIndexes('missing_index_metadata_table'));
    }

    public function testPostgresIndexesReportNamesOrderedColumnsAndUniqueness(): void
    {
        $database = getenv('DENOSYS_TEST_PGSQL_DATABASE');
        if (!$database) {
            self::markTestSkipped('Set DENOSYS_TEST_PGSQL_DATABASE for the PostgreSQL index integration test.');
        }

        $connection = (new ConnectionFactory())->make([
            'driver' => 'pgsql',
            'host' => getenv('DENOSYS_TEST_PGSQL_HOST') ?: '127.0.0.1',
            'port' => getenv('DENOSYS_TEST_PGSQL_PORT') ?: 5432,
            'database' => $database,
            'username' => getenv('DENOSYS_TEST_PGSQL_USERNAME') ?: 'postgres',
            'password' => getenv('DENOSYS_TEST_PGSQL_PASSWORD') ?: '',
        ]);
        $connection->statement('CREATE TEMP TABLE index_metadata_fixture (
            id BIGINT PRIMARY KEY,
            email VARCHAR(191) NOT NULL,
            region VARCHAR(20) NOT NULL
        )');
        $connection->statement('CREATE UNIQUE INDEX uq_email ON index_metadata_fixture (email)');
        $connection->statement('CREATE INDEX idx_region ON index_metadata_fixture (region)');
        $connection->statement('CREATE UNIQUE INDEX uq_email_region ON index_metadata_fixture (email, region)');

        $schema = new SchemaBuilder($connection, new PostgresSchemaGrammar());
        self::assertSame([
            ['name' => 'idx_region', 'columns' => ['region'], 'unique' => false, 'primary' => false],
            ['name' => 'index_metadata_fixture_pkey', 'columns' => ['id'], 'unique' => true, 'primary' => true],
            ['name' => 'uq_email', 'columns' => ['email'], 'unique' => true, 'primary' => false],
            ['name' => 'uq_email_region', 'columns' => ['email', 'region'], 'unique' => true, 'primary' => false],
        ], $schema->getIndexes('index_metadata_fixture'));
        self::assertSame([], $schema->getIndexes('missing_index_metadata_table'));
    }
}
