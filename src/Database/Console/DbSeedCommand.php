<?php

declare(strict_types=1);

namespace Denosys\Database\Console;

use Denosys\Console\CommandDefinition;
use Denosys\Console\CommandInterface;
use Denosys\Console\InputInterface;
use Denosys\Console\OutputInterface;
use Denosys\Database\Seeding\SeederRunner;
use Denosys\Database\Seeding\SeederInterface;

/**
 * Run database seeders.
 */
class DbSeedCommand implements CommandInterface
{
    public function __construct(
        private readonly SeederRunner $runner,
    ) {}

    public function getName(): string
    {
        return 'db:seed';
    }

    public function getDescription(): string
    {
        return 'Seed the database with records';
    }

    public function configure(): CommandDefinition
    {
        return (new CommandDefinition())
            ->addOption('class', 'c', CommandDefinition::OPTION_OPTIONAL, 'The seeder class to run')
            ->addOption('force', 'f', CommandDefinition::OPTION_NONE, 'Force run in production')
            ->setHelp(<<<HELP
Runs database seeders to populate the database with test/initial data.

Examples:
  php core db:seed                           # Run DatabaseSeeder
  php core db:seed --class=UserSeeder        # Run specific seeder
  php core db:seed -c UserSeeder             # Short form
HELP);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $seederClass = $input->getOption('class');
        
        $output->info('Running database seeders...');
        $output->writeln('');

        try {
            if ($seederClass) {
                // Run specific seeder
                $fullClass = $this->resolveSeederClass($seederClass);
                $this->runSeeder($fullClass, $output);
            } else {
                // Run DatabaseSeeder by default
                $defaultSeeder = $this->resolveSeederClass('DatabaseSeeder');
                
                if (!class_exists($defaultSeeder)) {
                    $output->error('DatabaseSeeder not found. Create one at database/seeders/DatabaseSeeder.php');
                    return 1;
                }
                
                $this->runSeeder($defaultSeeder, $output);
            }

            $output->writeln('');
            $output->success('Database seeding completed.');
            
            return 0;
        } catch (\Throwable $e) {
            $output->error('Seeding failed: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Run a seeder and output progress.
     * 
     * @param class-string<SeederInterface> $seederClass
     */
    private function runSeeder(string $seederClass, OutputInterface $output): void
    {
        $output->writeln("  <info>→</info> Running {$this->getBaseClassName($seederClass)}");
        
        $this->runner->run($seederClass);
        
        foreach ($this->runner->getExecuted() as $executedClass) {
            $output->writeln("    <comment>✓</comment> " . $this->getBaseClassName($executedClass));
        }
    }

    /**
     * Resolve a seeder class name from short name.
     */
    private function resolveSeederClass(string $name): string
    {
        // If already fully qualified
        if (str_contains($name, '\\')) {
            return $name;
        }

        // Try Database\Seeders namespace
        return "Database\\Seeders\\{$name}";
    }

    /**
     * Get the base class name without namespace.
     */
    private function getBaseClassName(string $class): string
    {
        $parts = explode('\\', $class);
        return end($parts);
    }
}
