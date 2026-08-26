<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

/**
 * A cycle through *two* classes: Category -> Categories -> Category. Detecting this needs the whole generation
 * stack, not just "am I my own child".
 */
final readonly class MutuallyRecursiveCategory
{
    private function __construct(
        public ContactName $label,
        public MutuallyRecursiveCategories $children,
    ) {}
}
