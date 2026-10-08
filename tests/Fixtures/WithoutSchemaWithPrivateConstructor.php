<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

/**
 * An ordinary class that never opted into being described, and keeps its constructor private — so whatever its way
 * in enforces, an analysis of its constructor would not know about. It is refused rather than derived.
 */
final readonly class WithoutSchemaWithPrivateConstructor
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        return new self($value);
    }
}
