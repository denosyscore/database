<?php

declare(strict_types=1);

use Denosys\Database\Connection\ConnectionFactory;
use Denosys\Database\Migration\MigrationRepository;
use Denosys\Database\Schema\Grammar\SqliteSchemaGrammar;
use Denosys\Database\Schema\SchemaBuilder;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class SqliteMigrationRepositoryTest extends TestCase
{
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testLogsAndReadsMigrationExecutionTimeOnSqlite(): void
    {
        $connection = new ConnectionFactory()->make([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $schema = new SchemaBuilder($connection, new SqliteSchemaGrammar());
        $repository = new MigrationRepository($connection, $schema);
        $repository->createRepository();

        $repository->log('create_users', 'checksum', 1);

        $record = $repository->getRan()['create_users'];
        self::assertSame(1, $record['batch']);
        self::assertSame('checksum', $record['checksum']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $record['executed_at']);
    }
}
