<?php

declare(strict_types=1);

namespace Neos\Schematic\Generation\Middleware;

use Neos\JsonSchema\BooleanSchema;
use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\NullSchema;
use Neos\JsonSchema\NumberSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Generation\SchemaGenerator;
use Neos\Schematic\Generation\SchemaMiddleware;
use Neos\Schematic\Generation\UnsupportedTypeException;
use Neos\Schematic\Instantiation\ProvidesSchemaFactory;
use Neos\Schematic\Schema\Schema;
use Neos\Schematic\Serialization\ProvidesSchemaExtractor;

/**
 * The bridge from the neutral, schematic-free {@see ProvidesSchema} contract (in neos/jsonschema) into a
 * coercion- and instantiation-capable schematic {@see Schema}: it recognises any class implementing
 * `ProvidesSchema`, reads its `::schema()`, and wraps that pure JSON Schema with a {@see ProvidesSchemaFactory}
 * target so the value object can be coerced *into*, not just validated.
 *
 * Scope: value objects backed by a *scalar* JSON Schema (the common case, e.g. content-repository's
 * `NodeAggregateId`). A composite (`object`/`array`) `ProvidesSchema` is not bridged here – describe those with a
 * dedicated middleware (e.g. the `ReflectionMiddleware` of the attributes namespace).
 */
final class ProvidesSchemaMiddleware implements SchemaMiddleware
{
    public function process(string $className, SchemaGenerator $next, SchemaGenerator $root): Schema
    {
        if (!is_a($className, ProvidesSchema::class, true)) {
            return $next->generate($className);
        }
        // TODO support records (Schema::object())
        $jsonSchema = $className::schema();
        if (!$jsonSchema instanceof StringSchema
            && !$jsonSchema instanceof IntegerSchema
            && !$jsonSchema instanceof NumberSchema
            && !$jsonSchema instanceof BooleanSchema
            && !$jsonSchema instanceof NullSchema
        ) {
            throw new UnsupportedTypeException(sprintf(
                'ProvidesSchemaMiddleware only bridges scalar-backed value objects; "%s" provides a %s',
                $className,
                $jsonSchema::class,
            ), 1751885200);
        }
        return Schema::scalar($jsonSchema, $className, ProvidesSchemaFactory::class, ProvidesSchemaExtractor::class);
    }
}
