<?php

declare(strict_types=1);

namespace Neos\Schematic\Schema;

use Neos\JsonSchema\AnyOfSchema as JsonAnyOfSchema;
use Neos\JsonSchema\ArraySchema as JsonArraySchema;
use Neos\JsonSchema\NullSchema as JsonNullSchema;
use Neos\JsonSchema\ObjectSchema as JsonObjectSchema;
use Neos\JsonSchema\OneOfSchema as JsonOneOfSchema;
use Neos\JsonSchema\Schema as JsonSchema;
use Neos\JsonSchema\Support\ObjectProperties;

/**
 * A coercion-capable schema node — one final value object for every {@see Kind} (scalar / object / list / union).
 *
 * It *references* a pure {@see JsonSchema} (the constraint data) and adds a coercion {@see self::$target}, a
 * {@see self::$factory} that builds it and a {@see self::$extractor} that reads it back out (all class-strings),
 * plus child nodes for composites ({@see self::$properties} for objects, {@see self::$items} for lists,
 * {@see self::$candidates} for unions). It carries
 * no services, closures or reflection handles, so the whole graph can be persisted to a PSR cache and restored
 * without reflection; the Coercer / Instantiator / Serializer services operate *on* it.
 *
 * Build via the named constructors {@see self::scalar()}, {@see self::object()}, {@see self::list()} and
 * {@see self::union()} — the private constructor and their typed signatures keep illegal shapes (a scalar carrying
 * properties, a list without items, a union without a discriminator, …) unrepresentable through the public API.
 */
final class Schema
{
    /**
     * @param array<string, self> $properties child property schemas (objects only; empty otherwise)
     * @param self|null $items child item schema (lists only; null otherwise)
     * @param array<non-empty-string, self> $candidates candidate schemas keyed by discriminator value (unions only; empty otherwise)
     * @param class-string|null $target the PHP class a value of this node coerces into (null = plain primitive)
     * @param class-string|null $factory the InstanceFactory that builds the target during instantiation (null = pass-through)
     * @param class-string|null $extractor the ValueExtractor that reads the target back out during serialization (null = pass-through)
     * @param bool $nullable whether `null` is an accepted value for this node in addition to its own shape
     */
    private function __construct(
        public readonly Kind $kind,
        public readonly JsonSchema $jsonSchema,
        public readonly array $properties,
        public readonly self|null $items,
        public readonly array $candidates,
        public readonly string|null $target,
        public readonly string|null $factory,
        public readonly string|null $extractor,
        public readonly bool $nullable,
    ) {}

    /**
     * @param class-string|null $target
     * @param class-string|null $factory
     * @param class-string|null $extractor
     */
    public static function scalar(JsonSchema $jsonSchema, string|null $target = null, string|null $factory = null, string|null $extractor = null): self
    {
        return new self(Kind::Scalar, $jsonSchema, [], null, [], $target, $factory, $extractor, false);
    }

    /**
     * @param array<string, self> $properties
     * @param class-string|null $target
     * @param class-string|null $factory
     * @param class-string|null $extractor
     */
    public static function object(JsonObjectSchema $jsonSchema, array $properties = [], string|null $target = null, string|null $factory = null, string|null $extractor = null): self
    {
        return new self(Kind::Object, $jsonSchema, $properties, null, [], $target, $factory, $extractor, false);
    }

    /**
     * @param class-string|null $target
     * @param class-string|null $factory
     * @param class-string|null $extractor
     */
    public static function list(JsonArraySchema $jsonSchema, self $items, string|null $target = null, string|null $factory = null, string|null $extractor = null): self
    {
        return new self(Kind::List, $jsonSchema, [], $items, [], $target, $factory, $extractor, false);
    }

    /**
     * A discriminated union: the candidate schemas of an interface's implementations, keyed by the value of the
     * discriminator property.
     *
     * The discriminator itself is *referenced* on the wrapped {@see JsonOneOfSchema} rather than replicated here,
     * keeping to the same rule as every other constraint — and its `mapping` holds class-strings, which a
     * consumer rendering an OpenAPI document rewrites into `#/components/schemas/…` references.
     *
     * @param array<non-empty-string, self> $candidates
     * @param class-string|null $target the interface (or abstract class) this union stands for
     * @param class-string|null $factory
     * @param class-string|null $extractor
     */
    public static function union(JsonOneOfSchema $jsonSchema, array $candidates, string|null $target = null, string|null $factory = null, string|null $extractor = null): self
    {
        if ($jsonSchema->discriminator === null) {
            throw new \InvalidArgumentException('A union schema requires a discriminator on its JSON Schema', 1751885500);
        }
        if ($candidates === []) {
            throw new \InvalidArgumentException('A union schema requires at least one candidate', 1751885501);
        }
        return new self(Kind::Union, $jsonSchema, [], null, $candidates, $target, $factory, $extractor, false);
    }

    /**
     * The candidate a raw value selects, or `null` when the discriminator is absent or names no candidate — in
     * which case the caller leaves the value alone and lets the Validator report it.
     */
    public function resolveCandidate(mixed $value): self|null
    {
        if ($this->kind !== Kind::Union || !is_array($value)) {
            return null;
        }
        $discriminatorValue = $value[$this->discriminatorPropertyName()] ?? null;
        if (!is_string($discriminatorValue)) {
            return null;
        }
        return $this->candidates[$discriminatorValue] ?? null;
    }

    /**
     * The property whose value selects a candidate. Unions only.
     */
    public function discriminatorPropertyName(): string
    {
        if ($this->kind !== Kind::Union || !$this->jsonSchema instanceof JsonOneOfSchema || $this->jsonSchema->discriminator === null) {
            throw new \LogicException('Only a union schema has a discriminator', 1751885502);
        }
        return $this->jsonSchema->discriminator->propertyName;
    }

    /**
     * The same node, but accepting `null` as well as its own shape.
     *
     * Nullability is a flag rather than a swapped-out `jsonSchema` on purpose: it leaves {@see self::$kind} and the
     * concrete wrapped schema intact, so the Coercer keeps dispatching on kind and {@see self::toJsonSchema()} can
     * still assemble children. (Storing `AnyOf(…, null)` in `jsonSchema` instead would make the `instanceof` guards
     * in {@see self::objectToJsonSchema()} / {@see self::listToJsonSchema()} fail and *silently drop every child*.)
     * The `null` branch is added once, at render time.
     */
    public function asNullable(): self
    {
        if ($this->nullable) {
            return $this;
        }
        return new self($this->kind, $this->jsonSchema, $this->properties, $this->items, $this->candidates, $this->target, $this->factory, $this->extractor, true);
    }

    /**
     * Render this node (and its children) back to a pure JSON Schema representation.
     */
    public function toJsonSchema(): JsonSchema
    {
        $rendered = match ($this->kind) {
            Kind::Scalar => $this->jsonSchema,
            Kind::Object => $this->objectToJsonSchema(),
            Kind::List => $this->listToJsonSchema(),
            Kind::Union => $this->unionToJsonSchema(),
        };
        if (!$this->nullable) {
            return $rendered;
        }
        return JsonAnyOfSchema::create($rendered, JsonNullSchema::create());
    }

    private function objectToJsonSchema(): JsonSchema
    {
        if ($this->properties === [] || !$this->jsonSchema instanceof JsonObjectSchema) {
            return $this->jsonSchema;
        }
        $rendered = [];
        foreach ($this->properties as $name => $property) {
            $rendered[$name] = $property->toJsonSchema();
        }
        return $this->jsonSchema->with(properties: ObjectProperties::create(...$rendered));
    }

    private function unionToJsonSchema(): JsonSchema
    {
        if (!$this->jsonSchema instanceof JsonOneOfSchema || $this->jsonSchema->discriminator === null) {
            return $this->jsonSchema;
        }
        $rendered = [];
        foreach ($this->candidates as $candidate) {
            $rendered[] = $candidate->toJsonSchema();
        }
        return JsonOneOfSchema::create(...$rendered)->withDiscriminator($this->jsonSchema->discriminator);
    }

    private function listToJsonSchema(): JsonSchema
    {
        if ($this->items === null || !$this->jsonSchema instanceof JsonArraySchema) {
            return $this->jsonSchema;
        }
        return $this->jsonSchema->with(items: $this->items->toJsonSchema());
    }
}
