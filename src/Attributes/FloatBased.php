<?php

declare(strict_types=1);

namespace Neos\Schematic\Attributes;

use Attribute;

/**
 * Marks a class as a float-based value object, optionally narrowed by the given bounds.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class FloatBased
{
    public function __construct(
        public readonly int|float|null $minimum = null,
        public readonly int|float|null $maximum = null,
    ) {}
}
