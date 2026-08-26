<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

/**
 * A union nested as a property, which is where the three-pass Coercer has to resolve candidates mid-traversal.
 */
final readonly class Drawing
{
    private function __construct(
        public ContactName $title,
        public Shape $shape,
    ) {}
}
