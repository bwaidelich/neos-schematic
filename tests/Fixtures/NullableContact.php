<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

final readonly class NullableContact
{
    public function __construct(
        public ContactName $name,
        public ContactName|null $nickname = null,
        public string|null $note = null,
    ) {}
}
