<?php

declare(strict_types=1);

namespace Denosys\Database\Console;

use Denosys\Contracts\Config\ConfigReader;
use Denosys\Console\CommandDefinition;
use Denosys\Console\CommandInterface;
use Denosys\Console\InputInterface;
use Denosys\Console\OutputInterface;
use Denosys\Container\ContainerInterface;
use Denosys\Database\Migration\MigrationException;
use Denosys\Database\Migration\Migrator;
use Denosys\Database\Seeding\SeederRunner;

/**
 * Run pending database migrations.
 */
class MigrateCommand implements CommandInterface
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?SeederRunner $seederRunner = null,
    ) {}

    public function getName(): string
    {
        return 'migrate';
    }

    public function getDescription(): string
    {
        return 'Run pending database migrations';
    }

    public function configure(): CommandDefinition
    {
        return (new CommandDefinition())
            ->addOption('pretend', null, CommandDefinition::OPTION_NONE, 'Show the SQL pending migrations would run, without running it')
            ->addOption('accept-changes', null, CommandDefinition::OPTION_NONE, 'Run although migrations that already ran have changed, and record their new checksums')
            ->addOption('force', null, CommandDefinition::OPTION_NONE, 'Force in production')
            ->addOption('seed', null, CommandDefinition::OPTION_OPTIONAL, 'Run seeders after migration (optionally specify class)', false)
            ->setHelp(<<<HELP
Run all pending database migrations.

Examples:
  php core migrate                    # Run migrations
  php core migrate --seed             # Run migrations then DatabaseSeeder
  php core migrate --seed=UserSeeder  # Run migrations then specific seeder
  php core migrate --pretend          # Show the SQL without running it

Only one migrate runs at a time per database; a second run waits for the
first. A migration that already ran is never run again, so migrate stops if
one has changed since: restore it and add a new migration instead.
HELP);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $pretend = $input->getOption('pretend');
        $force = $input->getOption('force');
        $seed = $input->getOption('seed');

        if ($pretend && $seed !== false) {
            $output->error('Cannot seed while previewing migrations.');
            return 2;
        }

        // Safety check for production
        if (!$pretend && !$force && $this->isProduction()) {
            if (!$output->confirm('Are you sure you want to run migrations in production?')) {
                $output->warning('Migration cancelled.');
                return 0;
            }
        }

        $output->info($pretend ? 'Previewing pending migrations...' : 'Running migrations...');

        $migrator = $this->migrator()
            ->pretend((bool) $pretend)
            ->acceptChanges((bool) $input->getOption('accept-changes'));

        try {
            $result = $migrator->run();
        } catch (MigrationException $e) {
            $output->error($e->getMessage());

            return 1;
        }

        if ($result->isEmpty()) {
            $output->comment($pretend ? 'No pending migrations. No changes made.' : 'Nothing to migrate.');
        } else {
            foreach ($result->getMigrations() as $record) {
                if ($record->success) {
                    if ($pretend) {
                        $output->writeln("<info>  Would run: {$record->name}</info>");
                        foreach ($this->sqlFor($record->name, $migrator->getPretendLog()) as $sql) {
                            $output->writeln("      {$sql}");
                        }
                    } else {
                        $time = number_format($record->time * 1000, 2);
                        $output->writeln("<info>  ✓ {$record->name}</info> ({$time}ms)");
                    }
                } else {
                    $output->writeln("<error>  ✗ {$record->name}: {$record->error}</error>");
                }
            }

            $output->newLine();
            
            if ($result->isSuccess()) {
                if ($pretend) {
                    $output->success(sprintf('%d migration(s) pending. No changes made.', $result->count()));
                } else {
                    $output->success(sprintf(
                        'Ran %d migration(s) in %.2f seconds.',
                        $result->count(),
                        $result->getTotalTime()
                    ));
                }
            } elseif ($pretend) {
                $output->warning(
                    'The preview stopped at a migration that could not run without the migrations before it. '
                    . 'Nothing was changed. ' . $result->getError()
                );
            } else {
                $output->error('Migration failed: ' . $result->getError());
                return 1;
            }
        }

        if ($pretend) {
            return 0;
        }

        // Run seeders if requested (--seed or --seed=ClassName)
        // When --seed passed: null (no value) or string (with value)
        // When not passed: false (default)
        if ($seed !== false) {
            $seederName = is_string($seed) && $seed !== '' ? $seed : null;
            return $this->runSeeders($output, $seederName);
        }

        return 0;
    }

    /**
     * Run database seeders.
     */
    private function runSeeders(OutputInterface $output, ?string $seederName = null): int
    {
        if ($this->seederRunner === null) {
            $output->warning('Seeder runner not available. Run db:seed separately.');
            return 0;
        }

        $output->newLine();
        $output->info('Running seeders...');

        try {
            // Resolve seeder class name
            if ($seederName !== null) {
                $seederClass = str_contains($seederName, '\\') 
                    ? $seederName 
                    : 'Database\\Seeders\\' . $seederName;
            } else {
                $seederClass = 'Database\\Seeders\\DatabaseSeeder';
            }
            
            if (!class_exists($seederClass)) {
                $output->warning("Seeder not found: {$seederClass}");
                return 1;
            }

            $this->seederRunner->run($seederClass);
            
            $output->success('Database seeding completed.');
            return 0;
        } catch (\Throwable $e) {
            $output->error('Seeding failed: ' . $e->getMessage());
            return 1;
        }
    }

    private function isProduction(): bool
    {
        // Without configuration, assume production so destructive runs still ask.
        $config = $this->container->has(ConfigReader::class) ? $this->container->get(ConfigReader::class) : null;
        if (!$config instanceof ConfigReader) {
            return true;
        }

        return ($config->get('app.env') ?? 'production') === 'production';
    }

    private function migrator(): Migrator
    {
        /** @var Migrator $migrator */
        $migrator = $this->container->get(Migrator::class);

        return $migrator;
    }

    /**
     * The statements the pretend log records under "-- $name".
     *
     * @param list<string> $log
     * @return list<string>
     */
    private function sqlFor(string $name, array $log): array
    {
        $start = array_search("-- {$name}", $log, true);
        if ($start === false) {
            return [];
        }

        $sql = [];
        foreach (array_slice($log, $start + 1) as $line) {
            if (str_starts_with($line, '-- ')) {
                break;
            }
            $sql[] = $line;
        }

        return $sql;
    }
}
