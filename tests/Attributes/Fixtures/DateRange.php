<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

/**
 * A plain shape (built from its constructor) whose invariant is enforced by the constructor throwing
 * \InvalidArgumentException — which the ShapeFactory adapts into an InvalidValue issue.
 */
final readonly class DateRange
{
    private function __construct(
        public int $start,
        public int $end,
    ) {
        if ($start >= $end) {
            throw new \InvalidArgumentException(sprintf('start (%d) must be less than end (%d)', $start, $end));
        }
    }
}
