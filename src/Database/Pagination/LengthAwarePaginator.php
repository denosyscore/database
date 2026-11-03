<?php

declare(strict_types=1);

namespace Denosys\Database\Pagination;

use Denosys\Support\Collection;
use InvalidArgumentException;

final readonly class LengthAwarePaginator
{
    public int $total;
    public int $currentPage;
    public int $lastPage;
    public int $from;
    public int $to;
    public ?int $previousPage;
    public ?int $nextPage;

    public function __construct(
        public Collection $items,
        int $total,
        int $requestedPage,
        public int $perPage,
    ) {
        if ($requestedPage < 1 || $perPage < 1) {
            throw new InvalidArgumentException('Page and per-page values must be positive.');
        }

        $this->total = max(0, $total);
        $this->lastPage = max(1, (int) ceil($this->total / $perPage));
        $this->currentPage = min($requestedPage, $this->lastPage);
        $this->from = $this->total === 0 ? 0 : (($this->currentPage - 1) * $perPage) + 1;
        $this->to = min($this->currentPage * $perPage, $this->total);
        $this->previousPage = $this->currentPage > 1 ? $this->currentPage - 1 : null;
        $this->nextPage = $this->currentPage < $this->lastPage ? $this->currentPage + 1 : null;
    }
}
