<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

use Neos\Schematic\Attributes\IntegerBased;

#[IntegerBased(minimum: 0, maximum: 150)]
final readonly class ContactAge
{
    private function __construct(
        public int $value,
    ) {}
}
