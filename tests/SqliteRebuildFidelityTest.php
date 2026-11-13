<?php

declare(strict_types=1);

use Denosys\Database\Connection\Connection;
use Denosys\Database\Connection\ConnectionFactory;
use Denosys\Database\Exceptions\DatabaseException;
use Denosys\Database\Schema\Blueprint;
use Denosys\Database\Schema\Grammar\SqliteSchemaGrammar;
use Denosys\Database\Schema\SchemaBuilder;
use PHPUnit\Framework\TestCase;

/**
 * A SQLite rebuild (changing a column, adding or dropping a foreign key) keeps
 * what the table had: collations, composite foreign keys, index definitions,
 * table options and the AUTOINCREMENT sequence.
 */
final class SqliteRebuildFidelityTest extends TestCase
{
    private Connection $connection;

    private SchemaBuilder $schema;

    protected function setUp(): void
    {
        $this->connection = (new ConnectionFactory())->make(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->schema = new SchemaBuilder($this->connection, new SqliteSchemaGrammar());
    }

    public function testColumnCollationsAreKept(): void
    {
        $this->connection->statement('CREATE TABLE people (id INTEGER PRIMARY KEY, name TEXT COLLATE NOCASE, note TEXT)');
        $this->connection->table('people')->insert(['name' => 'Ada', 'note' => 'x']);

        $this->changeNote('people');

        self::assertSame(1, $this->connection->table('people')->where('name', '=', 'ada')->count());
    }

    public function testCompositeForeignKeysStayOneConstraint(): void
    {
        $this->connection->statement('CREATE TABLE parents (a INTEGER, b INTEGER, PRIMARY KEY (a, b))');
        $this->connection->statement('CREATE TABLE children (id INTEGER PRIMARY KEY, a INTEGER, b INTEGER, note TEXT, CONSTRAINT children_parent FOREIGN KEY (a, b) REFERENCES parents (a, b))');
        $this->connection->table('parents')->insert(['a' => 1, 'b' => 2]);
        $this->connection->table('children')->insert(['a' => 1, 'b' => 2, 'note' => 'x']);

        $this->changeNote('children');

        $keys = $this->connection->select('PRAGMA foreign_key_list(children)');
        self::assertCount(2, $keys);
        self::assertSame([0, 0], array_map(static fn (object $key): int => (int) $key->id, $keys), 'the key was split');

        $this->schema->table('children', static function (Blueprint $table): void {
            $table->dropForeign('children_parent');
        });
        self::assertSame([], $this->connection->select('PRAGMA foreign_key_list(children)'));
    }

    public function testPartialAndCollatedIndexesAreKept(): void
    {
        $this->connection->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, active INTEGER, note TEXT)');
        $this->connection->statement('CREATE UNIQUE INDEX active_email ON users (email COLLATE NOCASE) WHERE active = 1');
        $this->connection->table('users')->insert(['email' => 'a@example.com', 'active' => 0]);
        $this->connection->table('users')->insert(['email' => 'a@example.com', 'active' => 0]);
        $this->connection->table('users')->insert(['email' => 'a@example.com', 'active' => 1]);

        $this->changeNote('users');

        $this->connection->table('users')->insert(['email' => 'a@example.com', 'active' => 0]);
        $this->expectException(DatabaseException::class);
        $this->connection->table('users')->insert(['email' => 'A@example.com', 'active' => 1]);
    }

    public function testTableOptionsAreKept(): void
    {
        $this->connection->statement('CREATE TABLE owners (id INTEGER PRIMARY KEY)');
        $this->connection->statement('CREATE TABLE codes (id INTEGER PRIMARY KEY, n INTEGER, owner_id INTEGER) STRICT');

        $this->schema->table('codes', static function (Blueprint $table): void {
            $table->foreign('owner_id')->references('id')->on('owners');
        });

        self::assertStringEndsWith('STRICT', (string) $this->connection->selectOne("SELECT sql FROM sqlite_master WHERE name = 'codes'")?->sql);
        $this->expectException(DatabaseException::class);
        $this->connection->table('codes')->insert(['n' => 'not a number']);
    }

    public function testIssuedAutoIncrementKeysAreNotReused(): void
    {
        $this->schema->create('tickets', static function (Blueprint $table): void {
            $table->id();
            $table->string('note');
        });
        $this->connection->table('tickets')->insert(['note' => 'a']);
        $this->connection->table('tickets')->insert(['note' => 'b']);
        $this->connection->table('tickets')->where('id', '=', 2)->delete();

        $this->changeNote('tickets');

        self::assertSame(3, (int) $this->connection->table('tickets')->insertGetId(['note' => 'c']));
    }

    public function testColumnIndexesRequestedWithAChangeAreCreated(): void
    {
        $this->schema->create('accounts', static function (Blueprint $table): void {
            $table->id();
            $table->string('note');
        });

        $this->schema->table('accounts', static function (Blueprint $table): void {
            $table->string('note', 50)->change();
            $table->string('email')->nullable()->unique();
            $table->string('region')->nullable()->index();
        });

        $names = array_column($this->schema->getIndexes('accounts'), 'name');
        self::assertContains('uq_accounts_email', $names);
        self::assertContains('idx_accounts_region', $names);
    }

    public function testAnUnquotedForeignKeyNameCanBeDropped(): void
    {
        $this->connection->statement('CREATE TABLE owners (id INTEGER PRIMARY KEY)');
        $this->connection->statement('CREATE TABLE pets (id INTEGER PRIMARY KEY, owner_id INTEGER, CONSTRAINT custom_fk FOREIGN KEY (owner_id) REFERENCES owners (id))');

        $this->schema->table('pets', static function (Blueprint $table): void {
            $table->dropForeign('custom_fk');
        });

        self::assertSame([], $this->connection->select('PRAGMA foreign_key_list(pets)'));
    }

    public function testDroppingAMissingForeignKeyIsRefused(): void
    {
        $this->connection->statement('CREATE TABLE pets (id INTEGER PRIMARY KEY, owner_id INTEGER)');

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('no such constraint');
        $this->schema->table('pets', static function (Blueprint $table): void {
            $table->dropForeign('missing_fk');
        });
    }

    private function changeNote(string $table): void
    {
        $this->schema->table($table, static function (Blueprint $blueprint): void {
            $blueprint->string('note', 50)->nullable()->change();
        });
    }
}
