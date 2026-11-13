<?php

declare(strict_types=1);

namespace Denosys\Database\Migration;

use RuntimeException;

/**
 * A migration run that could not start: another run holds the lock, or
 * migrations that already ran have changed since.
 */
final class MigrationException extends RuntimeException
{
    public static function locked(string $database, float $timeout): self
    {
        return new self(sprintf(
            'Another migration run holds the lock on [%s]; gave up after %.1f seconds. '
            . 'Wait for it to finish, then run the command again.',
            $database,
            $timeout,
        ));
    }

    /**
     * @param list<string> $migrations
     */
    public static function changed(array $migrations): self
    {
        return new self(
            "These migrations changed after they ran:\n  " . implode("\n  ", $migrations) . "\n"
            . 'A migration that already ran is never run again, so editing it does not change the database. '
            . 'Restore the files and add a new migration, or, if the change does not alter the schema, '
            . 'run with --accept-changes to record the new checksums.'
        );
    }

    public static function duplicate(string $name, string $first, string $second): self
    {
        return new self("Migration [{$name}] exists in two paths: {$first} and {$second}.");
    }
}
