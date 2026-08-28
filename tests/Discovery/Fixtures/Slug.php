<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Discovery\Fixtures;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;

/**
 * A value object supplying its own schema, which analysis must embed verbatim rather than re-derive.
 */
final readonly class Slug implements ProvidesSchema
{
    public function __construct(public string $value) {}

    public static function schema(): Schema
    {
        // memoized in a static variable, because a readonly class cannot declare a static property; the
        // instanceof (rather than a null check) is what tells a static analyzer the type that comes back
        return StringSchema::create(minLength: 1, maxLength: 100, pattern: '^[a-z0-9-]+$');
    }
}
