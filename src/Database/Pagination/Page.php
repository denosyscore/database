<?php

declare(strict_types=1);

namespace Denosys\Database\Pagination;

use InvalidArgumentException;

/** A page number and size, both at least 1. */
final readonly class Page
{
    private function __construct(
        public int $number,
        public int $perPage,
    ) {
    }

    /** @throws InvalidArgumentException when either value is below 1 */
    public static function of(int $number, int $perPage): self
    {
        if ($number < 1 || $perPage < 1) {
            throw new InvalidArgumentException('Page number and page size must be at least 1.');
        }

        return new self($number, $perPage);
    }

    /**
     * A page from request input. Values that are not whole numbers fall back
     * to page 1 and the default size; the size is clamped to 1..$max.
     *
     * @throws InvalidArgumentException when $max is below 1
     */
    public static function fromInput(mixed $number, mixed $perPage, int $default = 25, int $max = 100): self
    {
        if ($max < 1) {
            throw new InvalidArgumentException('The largest page size must be at least 1.');
        }

        return new self(
            max(1, self::integer($number) ?? 1),
            min($max, max(1, self::integer($perPage) ?? $default)),
        );
    }

    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/\A-?[0-9]+\z/', $value) === 1 ? (int) $value : null;
    }
}
