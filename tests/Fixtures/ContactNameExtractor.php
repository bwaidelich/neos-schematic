<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\Schematic\Schema\Schema;
use Neos\Schematic\Serialization\Serializer;
use Neos\Schematic\Serialization\UnextractableValue;
use Neos\Schematic\Serialization\ValueExtractor;

final class ContactNameExtractor implements ValueExtractor
{
    public function extract(Schema $schema, mixed $value, Serializer $serializer): mixed
    {
        if (!$value instanceof ContactName) {
            throw new UnextractableValue(sprintf('Expected a ContactName, got %s', get_debug_type($value)));
        }
        return $value->value;
    }
}
