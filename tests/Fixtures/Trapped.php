<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A named constructor the engine must not call: it fails loudly if it is, which is what pins down that building
 * goes to the constructor and nowhere else.
 */
final readonly class Trapped implements ProvidesSchema
{
    public function __construct(public string $value) {}

    public static function fromString(string $value): never
    {
        throw new \LogicException(sprintf('The engine called a named constructor with "%s"', $value), 1783600411);
    }

    public static function schema(): Schema
    {
        return AutoDiscoveringSchema::analyze(self::class);
    }
}
