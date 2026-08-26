<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;

/**
 * A value object that describes itself via {@see ProvidesSchema} — depends on neos/jsonschema only, yet can be
 * bridged into the schematic pipeline. Mirrors the content-repository `NodeAggregateId` shape.
 */
final readonly class ProductId implements ProvidesSchema
{
    private function __construct(
        public string $value,
    ) {}

    public static function fromString(string $value): self
    {
        $result = self::schema()->validate($value);
        if (!$result->valid) {
            throw new \InvalidArgumentException(sprintf('Invalid product id "%s": %s', $value, implode(', ', array_map(strval(...), $result->issues->toArray()))), 1751885300);
        }
        return new self($value);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        if (!$schema instanceof StringSchema) {
            $schema = StringSchema::create(pattern: '^p-[a-z0-9]{1,10}$');
        }
        return $schema;
    }
}
