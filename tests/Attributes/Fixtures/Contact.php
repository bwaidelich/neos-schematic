<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

use Neos\Schematic\Attributes\Description;

#[Description('A contact')]
final readonly class Contact
{
    private function __construct(
        public ContactName $name,
        public ContactAge $age,
    ) {}
}
