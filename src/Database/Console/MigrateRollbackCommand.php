<?php

declare(strict_types=1);

namespace Denosys\Database\Console;

use Denosys\Contracts\Config\ConfigReader;
use Denosys\Console\CommandDefinition;
use Denosys\Console\CommandInterface;
use Denosys\Console\InputInterface;
use Denosys\Console\OutputInterface;
use Denosys\Container\ContainerInterface;
use Denosys\Database\Migration\Migrator;

/**
 * Rollback database migrations.
 */
class MigrateRollbackCommand implements CommandInterface
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    public function getName(): string
    {
        return 'migrate:rollback';
    }

    public function getDescription(): string
    {
        return 'Rollback the last batch of migrations';
    }

    public function configure(): CommandDefinition
    {
        return (new CommandDefinition())
            ->addOption('step', null, CommandDefinition::OPTION_OPTIONAL, 'Number of batches to rollback', 1)
            ->addOption('force', null, CommandDefinition::OPTION_NONE, 'Force in production');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $steps = (int) ($input->getOption('step') ?: 1);
        $force = $input->getOption('force');

        if (!$force && $this->isProduction()) {
            if (!$output->confirm('Are you sure you want to rollback migrations in production?')) {
                $output->warning('Rollback cancelled.');
                return 0;
            }
        }

        $output->info("Rolling back {$steps} batch(es)...");

        $result = $this->migrator()->rollback($steps);

        if ($result->isEmpty()) {
            $output->comment('Nothing to rollback.');
            return 0;
        }

        foreach ($result->getMigrations() as $record) {
            $time = number_format($record->time * 1000, 2);
            
            if ($record->success) {
                $output->writeln("<info>  ✓ Rolled back: {$record->name}</info> ({$time}ms)");
            } else {
                $output->writeln("<error>  ✗ Failed: {$record->name}: {$record->error}</error>");
            }
        }

        $output->newLine();
        
        if ($result->isSuccess()) {
            $output->success(sprintf(
                'Rolled back %d migration(s) in %.2f seconds.',
                $result->count(),
                $result->getTotalTime()
            ));
            return 0;
        }

        $output->error('Rollback failed: ' . $result->getError());
        return 1;
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
}
