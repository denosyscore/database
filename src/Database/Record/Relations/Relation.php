<?php

declare(strict_types=1);

namespace Denosys\Database\Record\Relations;

use Denosys\Database\Record\Record;
use Denosys\Database\Record\RecordQuery;

/**
 * A relation between a record and records of another class. Relations are
 * strict: get() returns what with() loaded and throws RelationNotLoaded
 * otherwise; query() always runs a query.
 *
 * @template-covariant P of Record
 * @template R of Record
 */
interface Relation
{
    /** What identifies the relation among the parent's loaded relations. */
    public function key(): string;

    public function isLoaded(): bool;

    /** @return RecordQuery<R> */
    public function query(): RecordQuery;

    /**
     * Loads the relation onto every parent with one query, setting an empty
     * value on the parents without related records.
     *
     * @internal called by RecordQuery::with()
     * @param list<Record> $parents
     */
    public function eagerLoad(array $parents): void;
}
