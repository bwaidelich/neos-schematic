<?php

declare(strict_types=1);

namespace Neos\Schematic\Attributes;

use Attribute;

/**
 * Marks a class as an integer-based value object, optionally narrowed by the given bounds.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class IntegerBased
{
    public function __construct(
        public readonly int|null $minimum = null,
        public readonly int|null $maximum = null,
    ) {}
}
