<?php

declare(strict_types=1);

namespace Denosys\Database\Record;

use LogicException;

/**
 * Holds the record store that Persists uses. The framework sets it during
 * bootstrap; long-running workers reset it between jobs or requests, and
 * tests set their own. This one ambient object is the cost of Active Record.
 */
final class Records
{
    private static ?RecordStore $store = null;

    public static function use(RecordStore $store): void
    {
        self::$store = $store;
    }

    /** @throws LogicException when no store is set */
    public static function store(): RecordStore
    {
        return self::$store ?? throw new LogicException('No record store is set; call Records::use() during bootstrap.');
    }

    public static function reset(): void
    {
        self::$store = null;
    }
}
