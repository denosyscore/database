<?php

declare(strict_types=1);

namespace Denosys\Database\Pagination;

use Closure;

/**
 * One page of results with its position among all pages. A page past the
 * end is clamped to the last page; no results is page 1 of 1, with from and
 * to both 0.
 *
 * @template T
 */
final readonly class Paginated
{
    /** @param list<T> $items */
    private function __construct(
        public array $items,
        public int $total,
        public int $perPage,
        public int $currentPage,
        public int $lastPage,
        public int $from,
        public int $to,
        public ?int $previousPage,
        public ?int $nextPage,
    ) {
    }

    /**
     * @template U
     * @param Closure(int $page): list<U> $items fetches the given page
     * @return self<U>
     */
    public static function of(Page $page, int $total, Closure $items): self
    {
        $lastPage = max(1, (int) ceil($total / $page->perPage));
        $current = min($page->number, $lastPage);
        $list = $total === 0 ? [] : $items($current);
        $from = $list === [] ? 0 : ($current - 1) * $page->perPage + 1;

        return new self(
            $list,
            $total,
            $page->perPage,
            $current,
            $lastPage,
            $from,
            $list === [] ? 0 : $from + count($list) - 1,
            $current > 1 ? $current - 1 : null,
            $current < $lastPage ? $current + 1 : null,
        );
    }

    /**
     * The same page with each item converted, for display.
     *
     * @template U
     * @param Closure(T): U $convert
     * @return self<U>
     */
    public function map(Closure $convert): self
    {
        return new self(
            array_map($convert, $this->items),
            $this->total,
            $this->perPage,
            $this->currentPage,
            $this->lastPage,
            $this->from,
            $this->to,
            $this->previousPage,
            $this->nextPage,
        );
    }
}
