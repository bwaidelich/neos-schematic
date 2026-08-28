<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures\Drift;

use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * One string parameter, so the class *is* that value — while its hand-written schema describes an object with a
 * differently named property. The two never meet.
 */
final readonly class StructureForAValue implements ProvidesSchema
{
    public function __construct(public string $name) {}

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            properties: ObjectProperties::create(title: StringSchema::create()),
            additionalProperties: false,
            required: ['title'],
        );
    }
}
