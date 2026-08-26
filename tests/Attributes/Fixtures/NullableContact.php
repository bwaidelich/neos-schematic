<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

use Neos\Schematic\Attributes\Description;

#[Description('A contact whose optional details may be null')]
final readonly class NullableContact
{
    private function __construct(
        public ContactName $name,
        // The house style spells nullability `X|null`; reflection reports `?X` identically (both a
        // ReflectionNamedType with allowsNull()), so the middleware needs no separate handling for either.
        public ContactName|null $nickname,
        public ContactAge|null $age = null,
        public string|null $note = null,
    ) {}
}
