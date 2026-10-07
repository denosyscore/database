<?php

declare(strict_types=1);

use Denosys\Database\Connection\ConnectionFactory;
use Denosys\Database\Schema\Blueprint;
use Denosys\Database\Schema\Grammar\SqliteSchemaGrammar;
use Denosys\Database\Schema\SchemaBuilder;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class SqliteForeignKeyTest extends TestCase
{
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testDeclaredForeignKeyIsCreatedAndCascadesOnDelete(): void
    {
        $connection = new ConnectionFactory()->make([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $schema = new SchemaBuilder($connection, new SqliteSchemaGrammar());
        $schema->create('parents', static function (Blueprint $table): void {
            $table->id();
        });
        $schema->create('children', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id');
            $table->foreign('parent_id')->references('id')->on('parents')->cascadeOnDelete();
        });

        $keys = $connection->select('PRAGMA foreign_key_list(children)');
        self::assertCount(1, $keys);
        self::assertSame('parents', $keys[0]->table);
        self::assertSame('CASCADE', $keys[0]->on_delete);

        $connection->statement('INSERT INTO parents (id) VALUES (1)');
        $connection->statement('INSERT INTO children (id, parent_id) VALUES (1, 1)');
        $connection->statement('DELETE FROM parents WHERE id = 1');

        self::assertSame([], $connection->select('SELECT id FROM children'));
    }
}
