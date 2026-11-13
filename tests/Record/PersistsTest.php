<?php

declare(strict_types=1);

use Denosys\Database\Connection\ConnectionManager;
use Denosys\Database\Exceptions\RecordNotFound;
use Denosys\Database\Record\Persists;
use Denosys\Database\Record\Records;
use Denosys\Database\Record\RecordStore;
use PHPUnit\Framework\TestCase;
use RecordFixtures\Part;

require_once __DIR__ . '/fixtures.php';

final class PersistsTest extends TestCase
{
    protected function setUp(): void
    {
        $manager = new ConnectionManager();
        $manager->addConnection('default', ['driver' => 'sqlite', 'database' => ':memory:']);
        $manager->setDefaultConnection('default');
        $manager->connection()->statement('CREATE TABLE parts (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        Records::use(new RecordStore($manager));
    }

    protected function tearDown(): void
    {
        Records::reset();
    }

    public function testARecordRoundTrips(): void
    {
        $part = new Part();
        $part->name = 'bolt';
        self::assertFalse($part->exists());

        $part->save();
        self::assertTrue($part->exists());
        self::assertSame(1, $part->id);

        $loaded = Part::findOrFail(1);
        $loaded->name = 'nut';
        $loaded->save();
        $part->refresh();
        self::assertSame('nut', $part->name);

        self::assertSame(['nut'], array_map(static fn (Part $p): string => $p->name, Part::query()->get()));
        self::assertNull(Part::find(2));

        $part->delete();
        self::assertFalse($part->exists());
        $this->expectException(RecordNotFound::class);
        Part::findOrFail(1);
    }

    public function testStoreWithoutUseThrows(): void
    {
        Records::reset();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No record store is set; call Records::use() during bootstrap.');

        Part::find(1);
    }

    public function testPersistsDeclaresNoProperties(): void
    {
        self::assertSame([], (new ReflectionClass(Persists::class))->getProperties());
    }
}
