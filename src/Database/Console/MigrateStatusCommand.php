<?php

declare(strict_types=1);

namespace Denosys\Database\Console;

use Denosys\Console\CommandDefinition;
use Denosys\Console\CommandInterface;
use Denosys\Console\InputInterface;
use Denosys\Console\OutputInterface;
use Denosys\Container\ContainerInterface;
use Denosys\Database\Migration\Migrator;

/**
 * Show migration status.
 */
class MigrateStatusCommand implements CommandInterface
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    public function getName(): string
    {
        return 'migrate:status';
    }

    public function getDescription(): string
    {
        return 'Show the status of each migration';
    }

    public function configure(): CommandDefinition
    {
        return new CommandDefinition();
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->info('Migration Status');
        $output->newLine();

        $status = $this->migrator()->status();

        if (empty($status)) {
            $output->comment('No migrations found.');
            return 0;
        }

        $headers = ['Status', 'Migration', 'Batch', 'Checksum Valid'];
        $rows = [];

        /** @var array<string, array{ran: bool, batch: int|null, checksum: string|null, checksum_valid?: bool|null}> $status */
        foreach ($status as $name => $info) {
            $statusIcon = $info['ran'] ? '✓ Ran' : '○ Pending';
            $batch = $info['batch'] ?? '-';
            // Use null coalescing to handle optional checksum_valid key
            $checksumValidValue = $info['checksum_valid'] ?? null;
            $checksumValid = $checksumValidValue === null ? '-' : ($checksumValidValue ? '✓' : '✗ Modified!');

            $rows[] = [$statusIcon, $name, $batch, $checksumValid];
        }

        $output->table($headers, $rows);

        // Summary
        $ran = count(array_filter($status, fn($s) => $s['ran']));
        $pending = count($status) - $ran;
        
        $output->newLine();
        $output->writeln("Ran: {$ran} | Pending: {$pending}");

        return 0;
    }

    private function migrator(): Migrator
    {
        /** @var Migrator $migrator */
        $migrator = $this->container->get(Migrator::class);

        return $migrator;
    }
}
