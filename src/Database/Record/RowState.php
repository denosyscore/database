<?php

declare(strict_types=1);

namespace Denosys\Database\Record;

/**
 * What the store knows about one loaded record: the column values it was
 * loaded or last saved with, and its loaded relations.
 *
 * @internal
 */
final class RowState
{
    /**
     * @param array<string, mixed> $snapshot column => database value
     * @param array<string, mixed> $relations relation key => loaded value
     * @param array<string, array<string, mixed>> $pivots pivot values of a related record, by relation key
     */
    public function __construct(
        public array $snapshot,
        public array $relations = [],
        public array $pivots = [],
    ) {
    }
}
