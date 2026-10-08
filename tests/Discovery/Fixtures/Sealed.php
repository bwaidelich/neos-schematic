<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Discovery\Fixtures;

/**
 * No schema, and a private constructor: whatever its own way in enforces is nothing analysis can see.
 */
final readonly class Sealed
{
    private function __construct(public string $value) {}
}
