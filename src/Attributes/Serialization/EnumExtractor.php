<?php

declare(strict_types=1);

namespace Neos\Schematic\Attributes\Serialization;

use BackedEnum;
use Neos\Schematic\Attributes\Instantiation\EnumFactory;
use Neos\Schematic\Schema\Schema;
use Neos\Schematic\Serialization\Serializer;
use Neos\Schematic\Serialization\UnextractableValue;
use Neos\Schematic\Serialization\ValueExtractor;
use UnitEnum;

/**
 * Reads an enum case back to the scalar that identifies it — the mirror of {@see EnumFactory}: a *backed* enum by
 * its backing value, a *pure* enum by its case name.
 */
final class EnumExtractor implements ValueExtractor
{
    public function extract(Schema $schema, mixed $value, Serializer $serializer): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof UnitEnum) {
            return $value->name;
        }
        if (!is_object($value)) {
            return $value;
        }
        throw new UnextractableValue(sprintf('%s expected an enum case, got %s', self::class, get_debug_type($value)), 1751885306);
    }
}
