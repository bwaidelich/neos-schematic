<?php

declare(strict_types=1);

namespace Neos\Schematic\Instantiation;

use Neos\Schematic\Coercion\InvalidValue;
use Neos\Schematic\Schema\Kind;
use Neos\Schematic\Schema\Schema;

/**
 * Phase 2: builds the target PHP object graph from an already-coerced primitive value.
 *
 * For a node that names a {@see InstanceFactory}, it resolves and invokes it, passing itself as the recurse handle so the
 * factory drives descent into children. For a node *without* a factory it recurses structurally, leaving the value
 * as coerced primitives. This is the only place construction happens; it uses no reflection.
 */
final class Instantiator
{
    private readonly FactoryResolver $factoryResolver;

    public function __construct(FactoryResolver|null $factoryResolver = null)
    {
        $this->factoryResolver = $factoryResolver ?? new NewFactoryResolver();
    }

    /**
     * @param mixed $value an already-coerced value (as returned by the Coercer)
     * @throws InvalidValue if a InstanceFactory rejects the value
     */
    public function instantiate(Schema $schema, mixed $value): mixed
    {
        if ($schema->nullable && $value === null) {
            // short-circuit *before* the factory: a factory builds the target, and `null` is the absence of one.
            // Without this, ValueFactory would reach `new ContactName(null)` and fail with a TypeError.
            return null;
        }
        if ($schema->factory !== null) {
            return $this->factoryResolver->resolve($schema->factory)->instantiate($schema, $value, $this);
        }
        return match ($schema->kind) {
            Kind::Object => $this->instantiateObjectStructurally($schema, $value),
            Kind::List => $this->instantiateArrayStructurally($schema, $value),
            Kind::Union => $this->instantiateUnion($schema, $value),
            Kind::Scalar => $value,
        };
    }

    /**
     * A union node builds nothing itself: the discriminator picks the candidate, and that candidate's own
     * InstanceFactory does the work. The discriminator key is dropped first — it identifies the type rather than
     * being a property of it, and each candidate sets `additionalProperties: false`.
     */
    private function instantiateUnion(Schema $schema, mixed $value): mixed
    {
        $candidate = $schema->resolveCandidate($value);
        if ($candidate === null || !is_array($value)) {
            throw new InvalidValue(sprintf('Cannot resolve the "%s" discriminator to a candidate', $schema->discriminatorPropertyName()), []);
        }
        unset($value[$schema->discriminatorPropertyName()]);
        return $this->instantiate($candidate, $value);
    }

    private function instantiateObjectStructurally(Schema $schema, mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $result = [];
        foreach ($value as $name => $propertyValue) {
            $result[$name] = isset($schema->properties[$name])
                ? $this->instantiate($schema->properties[$name], $propertyValue)
                : $propertyValue;
        }
        return $result;
    }

    private function instantiateArrayStructurally(Schema $schema, mixed $value): mixed
    {
        if (!is_array($value) || $schema->items === null) {
            return $value;
        }
        $itemSchema = $schema->items;
        return array_map(fn(mixed $item): mixed => $this->instantiate($itemSchema, $item), $value);
    }
}
