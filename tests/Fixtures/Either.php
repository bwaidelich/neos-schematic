<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A shape whose one member may be either of two types — the union that a response holding one of several shapes
 * is written as. The two branches are deliberately different kinds of thing (an object and a single value), so
 * the union itself has no structural reading and only the value can say which branch it is.
 */
final readonly class Either implements ProvidesSchema
{
    public function __construct(public Registry|Slug $held) {}

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}
