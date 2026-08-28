<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures\Drift;

use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * `$author` has no default, so nothing can be built without it — but the schema does not require it, so validation
 * lets a payload through that construction cannot use.
 */
final readonly class OptionalRequired implements ProvidesSchema
{
    public function __construct(public string $title, public string $author) {}

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            properties: ObjectProperties::create(
                title: StringSchema::create(),
                author: StringSchema::create(),
            ),
            additionalProperties: false,
            required: ['title'],
        );
    }
}
