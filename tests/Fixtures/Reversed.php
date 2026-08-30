<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * A shape whose schema names its members in the opposite order to its constructor: the document is what the
 * emitted body is compared against, so the document's order is the one that goes out.
 */
final readonly class Reversed implements ProvidesSchema
{
    public function __construct(
        public int $first,
        public int $second,
    ) {}

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            properties: ObjectProperties::create(
                second: IntegerSchema::create(),
                first: IntegerSchema::create(),
            ),
            required: ['first', 'second'],
        );
    }
}
