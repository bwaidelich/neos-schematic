<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\JsonSchema\ArraySchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * A shape carrying a free-form map next to a list — the pair PHP cannot tell apart once both are empty, and the
 * reason serialization is schema-directed.
 *
 * The schema is hand-written because there is no map nature to derive one from: a dictionary travels as a builtin
 * `array`, described as an object that permits any member.
 */
final readonly class Registry implements ProvidesSchema
{
    /**
     * @param array<string, mixed> $entries
     * @param list<string> $names
     */
    public function __construct(
        public array $entries,
        public array $names,
    ) {}

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            properties: ObjectProperties::create(
                entries: ObjectSchema::create(additionalProperties: true),
                names: ArraySchema::create(items: StringSchema::create()),
            ),
            required: ['entries', 'names'],
        );
    }
}
