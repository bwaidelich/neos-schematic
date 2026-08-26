<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

/**
 * `mixed` reports `allowsNull()`, but it describes no shape — it must stay unsupported rather than becoming
 * "a nullable something".
 */
final readonly class UnsupportedMixed
{
    private function __construct(
        public mixed $anything,
    ) {}
}
