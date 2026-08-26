<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

final readonly class ContactAge
{
    public function __construct(
        public int $value,
    ) {}
}
