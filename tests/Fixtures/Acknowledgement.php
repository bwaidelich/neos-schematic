<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\JsonSchema\BooleanSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * `{"acknowledged": true}` — a one-member envelope whose member is a scalar, which is written exactly like the
 * value object {@see Rating} and means the opposite. Its schema is the only thing that can tell them apart.
 */
final readonly class Acknowledgement implements ProvidesSchema
{
    public function __construct(public bool $acknowledged = true) {}

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            properties: ObjectProperties::create(acknowledged: BooleanSchema::create(const: true)),
            required: ['acknowledged'],
        );
    }
}
