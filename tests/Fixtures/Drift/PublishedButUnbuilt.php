<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures\Drift;

use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * The schema publishes a `summary` the constructor never names: it is documented, accepted, and then dropped on
 * the floor by the instantiator.
 */
final readonly class PublishedButUnbuilt implements ProvidesSchema
{
    public function __construct(public string $title, public string $author) {}

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            properties: ObjectProperties::create(
                title: StringSchema::create(),
                author: StringSchema::create(),
                summary: StringSchema::create(),
            ),
            additionalProperties: false,
            required: ['title', 'author'],
        );
    }
}
