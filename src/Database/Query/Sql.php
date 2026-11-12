<?php

declare(strict_types=1);

namespace Denosys\Database\Query;

/**
 * Raw SQL for the query builder. The builder quotes every string it is given
 * as an identifier; an expression has to be marked with Sql::raw() so that it
 * is visible in review. Never put user input in raw SQL: bind it instead.
 */
final class Sql
{
    public static function raw(string $sql): Expression
    {
        return new Expression($sql);
    }
}
