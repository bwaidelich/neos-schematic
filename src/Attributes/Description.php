<?php

declare(strict_types=1);

namespace Neos\Schematic\Attributes;

use Attribute;

/**
 * Adds a human-readable description to a type (or constructor parameter). Rendered as the JSON Schema `description`.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PARAMETER)]
final class Description
{
    public function __construct(
        public readonly string $value,
    ) {}
}
