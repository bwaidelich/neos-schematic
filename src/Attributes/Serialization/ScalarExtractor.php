<?php

declare(strict_types=1);

namespace Neos\Schematic\Attributes\Serialization;

use Neos\Schematic\Attributes\Instantiation\ValueFactory;
use Neos\Schematic\Schema\Schema;
use Neos\Schematic\Serialization\Serializer;
use Neos\Schematic\Serialization\UnextractableValue;
use Neos\Schematic\Serialization\ValueExtractor;

/**
 * Reads a scalar-based value object back to its scalar — the mirror of {@see ValueFactory}, which passes a single
 * scalar to the target's constructor. The value therefore lives in the property named after that single constructor
 * parameter, so no new convention is introduced: whatever ValueFactory wrote, this reads.
 *
 * (Named for the {@see \Neos\Schematic\Schema\Kind} it serves rather than after ValueFactory, so that
 * "ValueExtractor" keeps meaning only the interface.)
 */
final class ScalarExtractor implements ValueExtractor
{
    public function extract(Schema $schema, mixed $value, Serializer $serializer): mixed
    {
        if (!is_object($value)) {
            return $value;
        }
        $target = $schema->target;
        if ($target === null) {
            throw new \LogicException(sprintf('%s requires the schema node to have a target', self::class), 1751885303);
        }
        $parameters = (new \ReflectionClass($value))->getConstructor()?->getParameters() ?? [];
        if ($parameters === []) {
            throw new UnextractableValue(sprintf('%s cannot read "%s": it has no constructor parameter to read back', self::class, $value::class), 1751885304);
        }
        return PropertyAccess::read($value, $parameters[0]->getName(), self::class);
    }
}
