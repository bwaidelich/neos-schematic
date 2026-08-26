<?php

declare(strict_types=1);

namespace Neos\Schematic\Attributes;

use Attribute;
use Neos\JsonSchema\Support\StringFormat;

/**
 * Marks a class as a string-based value object (a single string wrapped in a class), optionally narrowed by the
 * given constraints.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class StringBased
{
    public function __construct(
        public readonly int|null $minLength = null,
        public readonly int|null $maxLength = null,
        public readonly string|null $pattern = null,
        public readonly StringFormat|null $format = null,
    ) {}
}
