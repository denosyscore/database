<?php

declare(strict_types=1);

use Denosys\Database\Connection\ConnectionFactory;
use Denosys\Database\Exceptions\DatabaseException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class DatabaseExceptionTest extends TestCase
{
    public function testUnsupportedDriverThrowsTheDatabaseException(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Unsupported database driver');

        new ConnectionFactory()->make(['driver' => 'unsupported']);
    }

    #[RequiresPhpExtension('pdo_pgsql')]
    public function testConnectionFailurePreservesThePdoCause(): void
    {
        try {
            new ConnectionFactory()->make([
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => 1,
                'database' => 'unavailable',
                'username' => 'unavailable',
                'password' => 'unavailable',
            ]);

            self::fail('Expected the connection to fail.');
        } catch (DatabaseException $exception) {
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
        }
    }
}
