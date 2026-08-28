<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A private constructor and no named constructor at all: the class opted into being built by implementing
 * {@see ProvidesSchema}, so the engine calls that constructor — `private` fences off `new`, not the engine.
 */
final readonly class Reclusive implements ProvidesSchema
{
    public static function schema(): Schema
    {
        return AutoDiscoveringSchema::analyze(self::class);
    }

    private function __construct(public string $value) {}
}
