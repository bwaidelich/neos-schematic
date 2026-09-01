<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A value object keeping *both* its constructor and its state private: built through the private-constructor
 * entry point, but not readable from outside — which serialization reports rather than reflecting past.
 */
final readonly class Hidden implements ProvidesSchema
{
    private function __construct(private string $secret) {}

    public static function fromString(string $value): self
    {
        return \Neos\Schematic\Schematic::instanciate(self::class, $value)->valueOrThrow();
    }

    public function reveal(): string
    {
        return $this->secret;
    }

    public static function schema(): Schema
    {
        return AutoDiscoveringSchema::analyze(self::class);
    }
}
