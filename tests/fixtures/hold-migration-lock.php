<?php

declare(strict_types=1);

// Holds the migration lock in a separate process: prints "locked", sleeps,
// releases. Usage: php hold-migration-lock.php '<connection config json>' <seconds>

use Denosys\Database\Connection\ConnectionFactory;
use Denosys\Database\Migration\MigrationLock;

foreach ([dirname(__DIR__, 2) . '/vendor/autoload.php', dirname(__DIR__, 4) . '/vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

$config = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$connection = (new ConnectionFactory())->make($config);
$lock = new MigrationLock($connection, 5.0);
$lock->acquire();
echo "locked\n";
usleep((int) ((float) $argv[2] * 1_000_000));
$lock->release();
