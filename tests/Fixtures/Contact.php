<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

final readonly class Contact
{
    public function __construct(
        public ContactName $name,
        public ContactAge $age,
    ) {}
}
