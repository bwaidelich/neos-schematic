<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

final readonly class Circle implements Shape
{
    private function __construct(
        public ContactAge $radius,
    ) {}
}
