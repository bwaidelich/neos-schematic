<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

final readonly class Rectangle implements Shape
{
    private function __construct(
        public ContactAge $width,
        public ContactAge $height,
        public ContactName|null $label = null,
    ) {}
}
