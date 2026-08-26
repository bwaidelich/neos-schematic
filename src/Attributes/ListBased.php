<?php

declare(strict_types=1);

namespace Neos\Schematic\Attributes;

use Attribute;

/**
 * Marks a class as a list-based value object (a typed collection). The item schema is generated from
 * {@see self::$itemClassName} by re-entering the generation pipeline.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ListBased
{
    /**
     * @param class-string $itemClassName
     */
    public function __construct(
        public readonly string $itemClassName,
        public readonly int|null $minCount = null,
        public readonly int|null $maxCount = null,
    ) {}
}
