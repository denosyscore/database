<?php

declare(strict_types=1);

use Denosys\Database\Connection\ConnectionFactory;
use Denosys\Database\Exceptions\DatabaseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

enum DriverSupportStatus: string
{
    case Active = 'active';
}

final class DriverSupportTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function versions(): iterable
    {
        yield 'MySQL 8.0' => ['mysql', '8.0.36', false];
        yield 'MySQL 8.4' => ['mysql', '8.4.3', true];
        yield 'MySQL 9' => ['mysql', '9.1.0', true];
        yield 'MariaDB 10.6' => ['mysql', '10.6.17-MariaDB', false];
        yield 'MariaDB 10.11' => ['mysql', '10.11.9-MariaDB-ubu2204', true];
        yield 'MariaDB 11.4' => ['mysql', '5.5.5-11.4.3-MariaDB-log', true];
        yield 'MariaDB 10.11 from Ubuntu' => ['mysql', '10.11.13-MariaDB-0ubuntu0.24.04.1', true];
        yield 'MariaDB 10.6 from Ubuntu' => ['mysql', '5.5.5-10.6.18-MariaDB-0ubuntu0.22.04.1', false];
        yield 'PostgreSQL 14' => ['pgsql', '140013', false];
        yield 'PostgreSQL 15' => ['pgsql', '150008', true];
        yield 'SQLite 3.34' => ['sqlite', '3.34.1', false];
        yield 'SQLite 3.35' => ['sqlite', '3.35.0', true];
    }

    #[DataProvider('versions')]
    public function testServersBelowTheSupportedVersionsAreRefused(string $driver, string $version, bool $supported): void
    {
        if (!$supported) {
            $this->expectException(DatabaseException::class);
            $this->expectExceptionMessage('is not supported');
        }

        ConnectionFactory::assertSupportedVersion($driver, $version);
        $this->addToAssertionCount(1);
    }

    public function testMariaDbIsAcceptedAsADriverName(): void
    {
        $database = getenv('DENOSYS_TEST_MYSQL_DATABASE');
        if (!$database) {
            self::markTestSkipped('Set DENOSYS_TEST_MYSQL_DATABASE to run against a real MySQL or MariaDB server.');
        }

        $connection = (new ConnectionFactory())->make([
            'driver' => 'mariadb',
            'host' => getenv('DENOSYS_TEST_MYSQL_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DENOSYS_TEST_MYSQL_PORT') ?: 3306),
            'database' => $database,
            'username' => getenv('DENOSYS_TEST_MYSQL_USERNAME') ?: 'root',
            'password' => getenv('DENOSYS_TEST_MYSQL_PASSWORD') ?: '',
        ]);

        self::assertSame('mysql', $connection->getDriverName());
        self::assertSame(1, (int) $connection->selectOne('SELECT 1 AS one')?->one);
    }

    public function testUnknownDriversAreRefused(): void
    {
        $this->expectException(DatabaseException::class);

        (new ConnectionFactory())->make(['driver' => 'oracle']);
    }

    public function testDatesEnumsAndStringableValuesAreBound(): void
    {
        $connection = (new ConnectionFactory())->make(['driver' => 'sqlite', 'database' => ':memory:']);
        $connection->statement('CREATE TABLE bound (id INTEGER PRIMARY KEY, at TEXT, status TEXT, label TEXT)');

        $id = $connection->table('bound')->insertGetId([
            'at' => new DateTimeImmutable('2026-03-01 12:30:00', new DateTimeZone('Europe/Berlin')),
            'status' => DriverSupportStatus::Active,
            'label' => new class implements Stringable {
                public function __toString(): string
                {
                    return 'labelled';
                }
            },
        ]);

        self::assertSame(1, $id, 'integer keys are returned as integers');
        $row = $connection->table('bound')->first();
        self::assertNotNull($row);
        self::assertSame('2026-03-01 11:30:00', $row->at, 'dates are stored in UTC');
        self::assertSame('active', $row->status);
        self::assertSame('labelled', $row->label);
        self::assertSame(1, $connection->table('bound')->where('status', '=', DriverSupportStatus::Active)->count());
    }

    public function testUnitEnumsAreRefusedAsBindings(): void
    {
        $connection = (new ConnectionFactory())->make(['driver' => 'sqlite', 'database' => ':memory:']);

        $this->expectException(InvalidArgumentException::class);
        $connection->select('SELECT ?', [DriverSupportUnit::One]);
    }
}

enum DriverSupportUnit
{
    case One;
}
