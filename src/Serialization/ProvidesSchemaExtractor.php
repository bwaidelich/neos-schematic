<?php

declare(strict_types=1);

namespace Neos\Schematic\Serialization;

use Neos\Schematic\Schema\Schema;

/**
 * The {@see ValueExtractor} counterpart to {@see \Neos\Schematic\Instantiation\ProvidesSchemaFactory}, for a class
 * that describes itself via {@see \Neos\JsonSchema\ProvidesSchema}.
 *
 * `ProvidesSchema` defines the *forward* direction only (`::schema()` plus a `from*` named constructor), so the way
 * back is a convention, tried in order: `JsonSerializable::jsonSerialize()`, a public `$value` property, then
 * `__toString()`. A class offering none of them cannot be serialized through this bridge — say so loudly rather
 * than guess at its internals.
 */
final class ProvidesSchemaExtractor implements ValueExtractor
{
    public function extract(Schema $schema, mixed $value, Serializer $serializer): mixed
    {
        if (!is_object($value)) {
            // already a primitive (the bridge only ever describes scalar-backed value objects)
            return $value;
        }
        if ($value instanceof \JsonSerializable) {
            return $value->jsonSerialize();
        }
        if (property_exists($value, 'value')) {
            return (new \ReflectionProperty($value, 'value'))->getValue($value);
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }
        throw new UnextractableValue(sprintf(
            'Cannot read "%s" back out: a ProvidesSchema value object must implement JsonSerializable or Stringable, or expose a $value property',
            $value::class,
        ), 1751885300);
    }
}
