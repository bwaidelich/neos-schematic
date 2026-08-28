<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures\Drift;

use Neos\JsonSchema\Nullable;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * A hand-written schema that accepts a null the constructor refuses: validation passes, and construction then
 * fails with a `TypeError` nothing in the payload explains.
 */
final readonly class NullWidened implements ProvidesSchema
{
    public function __construct(public string $title, public string $subtitle) {}

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            properties: ObjectProperties::create(
                title: StringSchema::create(),
                subtitle: Nullable::wrap(StringSchema::create()),
            ),
            additionalProperties: false,
            required: ['title', 'subtitle'],
        );
    }
}
