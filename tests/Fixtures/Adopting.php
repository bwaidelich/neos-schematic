<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * A class that owns a schema and names, as its one member, a class that owns none.
 *
 * The schema is hand-written on purpose: analysis would have refused the member while *building* the schema, and
 * what this fixture is for is the refusal on the way *through* — a payload that validates, descending into a
 * member that never opted in.
 */
final readonly class Adopting implements ProvidesSchema
{
    public function __construct(public WithoutSchemaWithPrivateConstructor $held) {}

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            properties: ObjectProperties::create(
                held: ObjectSchema::create(additionalProperties: true),
            ),
            required: ['held'],
        );
    }
}
