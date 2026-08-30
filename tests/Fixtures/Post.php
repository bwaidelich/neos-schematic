<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use JsonSerializable;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;
use Neos\Schematic\Schematic;

/**
 * A shape over everything at once: a value object with its own schema, a plain scalar, an enum, a collection, and
 * an optional property.
 */
final readonly class Post implements JsonSerializable, ProvidesSchema
{
    public function __construct(
        public Slug $slug,
        public Author $author,
        public Status $status,
        public Tags $tags,
        public string|null $subtitle = null,
    ) {}

    public function jsonSerialize(): mixed
    {
        return Schematic::serialize(self::schema(), $this);
    }

    public static function schema(): Schema
    {
        return AutoDiscoveringSchema::analyze(self::class);
    }
}
