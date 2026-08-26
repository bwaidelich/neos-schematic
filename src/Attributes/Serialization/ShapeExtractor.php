<?php

declare(strict_types=1);

namespace Neos\Schematic\Attributes\Serialization;

use Neos\JsonSchema\BooleanSchema as JsonBooleanSchema;
use Neos\JsonSchema\IntegerSchema as JsonIntegerSchema;
use Neos\JsonSchema\NumberSchema as JsonNumberSchema;
use Neos\JsonSchema\StringSchema as JsonStringSchema;
use Neos\Schematic\Attributes\Instantiation\ShapeFactory;
use Neos\Schematic\Schema\Kind;
use Neos\Schematic\Schema\Schema;
use Neos\Schematic\Serialization\Serializer;
use Neos\Schematic\Serialization\ValueExtractor;

/**
 * Reads a shape back into an array — the mirror of {@see ShapeFactory}, which maps each schema property onto the
 * constructor parameter of the same name.
 *
 * Every property the schema declares is emitted, including those whose value equals the constructor default. The
 * schema advertises them, so omitting them would make the emitted document narrower than the published contract.
 */
final class ShapeExtractor implements ValueExtractor
{
    public function extract(Schema $schema, mixed $value, Serializer $serializer): mixed
    {
        if ($schema->kind !== Kind::Object) {
            throw new \LogicException(sprintf('%s requires an object schema', self::class), 1751885305);
        }
        if (!is_object($value)) {
            return $value;
        }
        $result = [];
        foreach ($schema->properties as $name => $propertySchema) {
            $constant = self::constantValue($propertySchema);
            if ($constant !== null && !property_exists($value, $name)) {
                // A `const` property has exactly one possible value, so the schema already *is* the answer and
                // there is nothing to read off the instance. This is how a discriminator tag gets emitted: the
                // union pins it on each candidate, but no implementation declares a property for it.
                $result[$name] = $constant;
                continue;
            }
            $result[$name] = $serializer->serialize($propertySchema, PropertyAccess::read($value, $name, self::class));
        }
        return $result;
    }

    private static function constantValue(Schema $propertySchema): string|int|float|bool|null
    {
        if ($propertySchema->kind !== Kind::Scalar) {
            return null;
        }
        $jsonSchema = $propertySchema->jsonSchema;
        return match ($jsonSchema::class) {
            JsonStringSchema::class => $jsonSchema->const,
            JsonIntegerSchema::class => $jsonSchema->const,
            JsonNumberSchema::class => $jsonSchema->const,
            JsonBooleanSchema::class => $jsonSchema->const,
            default => null,
        };
    }
}
