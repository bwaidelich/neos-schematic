<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures\Drift;

use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * A type that is only ever written out: it publishes a schema for the document it appears in, and emits itself
 * through a `jsonSerialize()` of its own, under a name its constructor never uses. Nothing maps a payload onto it,
 * so the constructor is beside the point — and {@see \Neos\Schematic\Conformance::check()} says so, loudly,
 * while {@see \Neos\Schematic\Conformance::checkSerialization()} finds nothing wrong.
 */
final readonly class EmittedOnly implements \JsonSerializable, ProvidesSchema
{
    public function __construct(public string $reason) {}

    public function jsonSerialize(): mixed
    {
        return ['detail' => $this->reason];
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            properties: ObjectProperties::create(detail: StringSchema::create()),
            additionalProperties: false,
            required: ['detail'],
        );
    }
}
