<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

final readonly class ContactName
{
    public function __construct(
        public string $value,
    ) {}
}
