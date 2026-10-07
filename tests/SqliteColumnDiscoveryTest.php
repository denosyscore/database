<?php

declare(strict_types=1);

use Denosys\Database\Connection\ConnectionFactory;
use Denosys\Database\Schema\Blueprint;
use Denosys\Database\Schema\Grammar\SqliteSchemaGrammar;
use Denosys\Database\Schema\SchemaBuilder;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class SqliteColumnDiscoveryTest extends TestCase
{
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testColumnNamesAndPresenceAreReportedFromSqlite(): void
    {
        $connection = (new ConnectionFactory())->make([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $schema = new SchemaBuilder($connection, new SqliteSchemaGrammar());
        $schema->create('accounts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('plan_id')->nullable();
        });

        self::assertSame(['id', 'plan_id'], $schema->getColumns('accounts'));
        self::assertTrue($schema->hasColumn('accounts', 'plan_id'));
        self::assertFalse($schema->hasColumn('accounts', 'missing'));
    }
}
