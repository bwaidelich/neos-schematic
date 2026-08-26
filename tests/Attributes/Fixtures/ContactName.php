<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

use Neos\Schematic\Attributes\Description;
use Neos\Schematic\Attributes\StringBased;

#[Description('The full name of a contact')]
#[StringBased(minLength: 1, maxLength: 200)]
final readonly class ContactName
{
    private function __construct(
        public string $value,
    ) {}
}
