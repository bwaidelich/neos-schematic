<?php

declare(strict_types=1);

namespace Neos\Schematic\Coercion;

use Neos\JsonSchema\IntegerSchema as JsonIntegerSchema;
use Neos\JsonSchema\NumberSchema as JsonNumberSchema;
use Neos\JsonSchema\Schema as JsonSchema;
use Neos\Schematic\Schema\Kind;
use Neos\Schematic\Schema\Schema;

/**
 * Phase 1: validates and normalizes raw input against a {@see Schema} into a schema-conformant *primitive* value
 * (scalars, and arrays for objects/lists/unions). Reflection-free, creates no instances.
 *
 * It performs only the *non-standard* part itself – type normalization (e.g. the numeric string `"45"` → `45`) –
 * and then delegates all constraint checking to the pure {@see \Neos\JsonSchema\Validation\Validator} in
 * neos/jsonschema (via `toJsonSchema()->validate()`), so the constraint logic lives in exactly one place. Aggregates
 * every violation as a {@see \Neos\JsonSchema\Validation\Issue}.
 */
final class Coercer
{
    #[\NoDiscard('inspect the CoercionResult; discarding it means the coercion was pointless')]
    public function coerce(Schema $schema, mixed $input): CoercionResult
    {
        $normalized = $this->normalize($schema, $input);
        $result = $schema->toJsonSchema()->validate($normalized);
        if (!$result->valid) {
            return CoercionResult::failure($result->issues);
        }
        return CoercionResult::success($this->project($schema, $normalized));
    }

    /**
     * Best-effort type normalization only (no constraint checks): produces the value the Validator then judges.
     * Values it cannot normalize are passed through unchanged, so the Validator reports the precise violation.
     */
    private function normalize(Schema $schema, mixed $input): mixed
    {
        return match ($schema->kind) {
            Kind::Scalar => $this->normalizeScalar($schema->jsonSchema, $input),
            Kind::Object => $this->normalizeObject($schema, $input),
            Kind::List => $this->normalizeArray($schema, $input),
        };
    }

    private function normalizeScalar(JsonSchema $json, mixed $input): mixed
    {
        if ($json instanceof JsonIntegerSchema && is_string($input) && preg_match('/^-?\d+$/', $input) === 1) {
            return (int) $input;
        }
        if ($json instanceof JsonNumberSchema && is_string($input) && is_numeric($input)) {
            return $input + 0;
        }
        return $input;
    }

    private function normalizeObject(Schema $schema, mixed $input): mixed
    {
        if (is_object($input)) {
            $input = get_object_vars($input);
        }
        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            return $input;
        }
        $normalized = [];
        foreach ($input as $key => $value) {
            $normalized[$key] = isset($schema->properties[$key])
                ? $this->normalize($schema->properties[$key], $value)
                : $value;
        }
        return $normalized;
    }

    private function normalizeArray(Schema $schema, mixed $input): mixed
    {
        if (!is_array($input) || ($input !== [] && !array_is_list($input))) {
            return $input;
        }
        $itemSchema = $schema->items ?? throw new UnsupportedSchemaException('A list schema node must carry an items schema');
        return array_map(fn(mixed $item): mixed => $this->normalize($itemSchema, $item), $input);
    }

    /**
     * The success value: the normalized primitives projected onto the schema's known structure (object properties
     * in schema order, unknown keys dropped), ready for the Instantiator. Only reached once validation has passed.
     */
    private function project(Schema $schema, mixed $normalized): mixed
    {
        return match ($schema->kind) {
            Kind::Object => $this->projectObject($schema, $normalized),
            Kind::List => $this->projectArray($schema, $normalized),
            Kind::Scalar => $normalized,
        };
    }

    private function projectObject(Schema $schema, mixed $normalized): mixed
    {
        if (!is_array($normalized)) {
            return $normalized;
        }
        $result = [];
        foreach ($schema->properties as $name => $propertySchema) {
            if (array_key_exists($name, $normalized)) {
                $result[$name] = $this->project($propertySchema, $normalized[$name]);
            }
        }
        return $result;
    }

    private function projectArray(Schema $schema, mixed $normalized): mixed
    {
        if (!is_array($normalized)) {
            return $normalized;
        }
        $itemSchema = $schema->items ?? throw new UnsupportedSchemaException('A list schema node must carry an items schema');
        return array_map(fn(mixed $item): mixed => $this->project($itemSchema, $item), $normalized);
    }
}
