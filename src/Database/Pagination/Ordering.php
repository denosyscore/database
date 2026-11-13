<?php

declare(strict_types=1);

namespace Denosys\Database\Pagination;

use Denosys\Database\Query\Builder;
use InvalidArgumentException;

/**
 * A stable sort order for paging: one or more column names, each ascending
 * or descending. Only column names are accepted, never expressions.
 */
final readonly class Ordering
{
    /** @param non-empty-list<array{column: string, direction: 'asc'|'desc'}> $terms */
    private function __construct(private array $terms)
    {
    }

    public static function ascending(string $column): self
    {
        return new self([self::term($column, 'asc')]);
    }

    public static function descending(string $column): self
    {
        return new self([self::term($column, 'desc')]);
    }

    public function thenAscending(string $column): self
    {
        return new self([...$this->terms, self::term($column, 'asc')]);
    }

    public function thenDescending(string $column): self
    {
        return new self([...$this->terms, self::term($column, 'desc')]);
    }

    #[\NoDiscard('queries are immutable: use the query this returns')]
    public function applyTo(Builder $query): Builder
    {
        foreach ($this->terms as $term) {
            $query = $query->orderBy($term['column'], $term['direction']);
        }

        return $query;
    }

    /**
     * @param 'asc'|'desc' $direction
     * @return array{column: string, direction: 'asc'|'desc'}
     */
    private static function term(string $column, string $direction): array
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*\z/', $column) !== 1) {
            throw new InvalidArgumentException("Ordering needs a column name, not {$column}.");
        }

        return ['column' => $column, 'direction' => $direction];
    }
}
