<?php

declare(strict_types=1);

namespace Tests;

use Denosys\Console\Application;
use Denosys\Database\Console\MigrateCommand;
use Denosys\Container\Container;
use Denosys\Database\Connection\Connection;
use Denosys\Database\Grammar\SqliteGrammar;
use Denosys\Database\Migration\MigrationRepository;
use Denosys\Database\Migration\Migrator;
use Denosys\Database\Schema\Grammar\SqliteSchemaGrammar;
use Denosys\Database\Schema\SchemaBuilder;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class MigrateCommandPreviewTest extends TestCase
{
    public function testPreviewListsPendingMigrationWithoutWritingHistory(): void
    {
        [$console, $schema] = $this->fixture();
        $output = new BufferedOutput();

        $status = $console->getSymfonyApplication()->run(new StringInput('migrate --pretend --force'), $output);
        $message = $output->fetch();

        self::assertSame(0, $status);
        self::assertStringContainsString('Would run: 0001_create_widgets', $message);
        self::assertStringContainsString('No changes made.', $message);
        self::assertFalse($schema->hasTable('schema_migrations'));
        self::assertFalse($schema->hasTable('widgets'));
    }

    public function testPreviewRejectsSeedingBeforeDoingAnyWork(): void
    {
        [$console, $schema] = $this->fixture();
        $output = new BufferedOutput();

        $status = $console->getSymfonyApplication()->run(new StringInput('migrate --pretend --seed --force'), $output);

        self::assertSame(2, $status);
        self::assertStringContainsString('seed', strtolower($output->fetch()));
        self::assertFalse($schema->hasTable('schema_migrations'));
        self::assertFalse($schema->hasTable('widgets'));
    }

    /** @return array{Application, SchemaBuilder} */
    private function fixture(): array
    {
        $connection = new Connection(new PDO('sqlite::memory:'), new SqliteGrammar());
        $schema = new SchemaBuilder($connection, new SqliteSchemaGrammar());
        $repository = new MigrationRepository($connection, $schema);
        $migrator = new Migrator($connection, $schema, $repository, __DIR__ . '/fixtures/console-migrations');
        $container = new Container();
        $container->instance(Migrator::class, $migrator);
        $console = new Application($container);
        $console->add(new MigrateCommand($container));

        return [$console, $schema];
    }
}
