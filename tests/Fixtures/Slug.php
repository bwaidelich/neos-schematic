<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

/**
 * The canonical value object: a private constructor reached through a closure it hands over itself, and a schema
 * it owns. Whoever builds it — its own named constructor, or the engine assembling a Post — goes through the same
 * validation.
 */
final readonly class Slug implements ProvidesSchema
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(minLength: 1, maxLength: 100, pattern: '^[a-z0-9-]+$');
    }
}
