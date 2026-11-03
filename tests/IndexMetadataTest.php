<?php

declare(strict_types=1);

use Denosys\Database\Connection\ConnectionFactory;
use Denosys\Database\Schema\Blueprint;
use Denosys\Database\Schema\Grammar\SqliteSchemaGrammar;
use Denosys\Database\Schema\SchemaBuilder;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class IndexMetadataTest extends TestCase
{
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testIndexesReportNamesOrderedColumnsAndUniqueness(): void
    {
        $connection = (new ConnectionFactory())->make([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $schema = new SchemaBuilder($connection, new SqliteSchemaGrammar());
        $schema->create('accounts', static function (Blueprint $table): void {
            $table->id();
            $table->string('email', 191)->unique();
            $table->string('region', 20);
            $table->index('region', 'idx_region');
            $table->unique(['email', 'region'], 'uq_email_region');
        });

        self::assertSame([
            ['name' => 'PRIMARY', 'columns' => ['id'], 'unique' => true, 'primary' => true],
            ['name' => 'idx_region', 'columns' => ['region'], 'unique' => false, 'primary' => false],
            ['name' => 'uq_accounts_email', 'columns' => ['email'], 'unique' => true, 'primary' => false],
            ['name' => 'uq_email_region', 'columns' => ['email', 'region'], 'unique' => true, 'primary' => false],
        ], $schema->getIndexes('accounts'));
        self::assertSame([], $schema->getIndexes('missing'));
    }
}
