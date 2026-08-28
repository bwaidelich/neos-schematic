<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A scalar value object with a *public* constructor and no schema of its own: the engine can build it directly,
 * and analysis derives `{"type": "string"}` for it.
 */
final readonly class Author implements ProvidesSchema
{
    public function __construct(public string $name) {}

    public static function schema(): Schema
    {
        return AutoDiscoveringSchema::analyze(self::class);
    }
}
