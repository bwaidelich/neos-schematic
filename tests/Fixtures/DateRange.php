<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

/**
 * A value object whose invariant (start < end) cannot be expressed in the individual property schemas,
 * so its factory surfaces a construction failure as {@see \Neos\Schematic\Coercion\InvalidValue}.
 */
final readonly class DateRange
{
    public function __construct(
        public int $start,
        public int $end,
    ) {
        if ($start >= $end) {
            throw new \InvalidArgumentException(sprintf('start (%d) must be less than end (%d)', $start, $end));
        }
    }
}
