<?php

declare(strict_types=1);

namespace Neos\Schematic\Attributes\Serialization;

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
            $result[$name] = $serializer->serialize($propertySchema, PropertyAccess::read($value, $name, self::class));
        }
        return $result;
    }
}
