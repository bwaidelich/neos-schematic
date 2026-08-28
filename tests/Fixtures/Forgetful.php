<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;

/**
 * A class that builds its schema afresh on every call, i.e. memoizes nothing — and is therefore asked again every
 * time. Building it once is the class's own business, said with a `static` in `schema()`; nothing holds a schema
 * on a class's behalf.
 */
final class Forgetful implements ProvidesSchema
{
    public static int $calls = 0;

    public function __construct(public readonly string $value) {}

    public static function schema(): Schema
    {
        self::$calls++;
        return StringSchema::create(minLength: 1);
    }
}
