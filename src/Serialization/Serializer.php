<?php

declare(strict_types=1);

namespace Neos\Schematic\Serialization;

use Neos\Schematic\Schema\Kind;
use Neos\Schematic\Schema\Schema;

/**
 * The reverse of the two mapping phases: turns a target object graph back into schema-conformant *primitives*
 * (scalars, and arrays for objects/lists) — the shape `json_encode()` expects, and the same shape the Coercer
 * produces on the way in.
 *
 * For a node that names a {@see ValueExtractor} it resolves and invokes it, passing itself as the recurse handle so
 * the extractor drives descent into children. For a node *without* an extractor it recurses structurally, assuming
 * the value is already primitives. Mirrors {@see \Neos\Schematic\Instantiation\Instantiator} step for step.
 *
 * Because the traversal is driven by the same Schema that renders to JSON Schema, the emitted structure and the
 * advertised schema cannot drift apart — which is the whole reason this is schema-driven rather than a generic
 * reflecting normalizer.
 */
final class Serializer
{
    private readonly ExtractorResolver $extractorResolver;

    public function __construct(ExtractorResolver|null $extractorResolver = null)
    {
        $this->extractorResolver = $extractorResolver ?? new NewExtractorResolver();
    }

    /**
     * @param mixed $value the target instance (or already-primitive value) described by $schema
     * @throws UnextractableValue if the target does not expose the state the schema describes
     */
    public function serialize(Schema $schema, mixed $value): mixed
    {
        if ($schema->nullable && $value === null) {
            // mirrors the Instantiator: `null` is the absence of a target, so no extractor is consulted
            return null;
        }
        if ($schema->extractor !== null) {
            return $this->extractorResolver->resolve($schema->extractor)->extract($schema, $value, $this);
        }
        return match ($schema->kind) {
            Kind::Object => $this->serializeObjectStructurally($schema, $value),
            Kind::List => $this->serializeArrayStructurally($schema, $value),
            Kind::Union => $this->serializeUnion($schema, $value),
            Kind::Scalar => $value,
        };
    }

    /**
     * The mirror of the Instantiator's union handling. Going *out*, the candidate is selected by the instance's
     * class rather than by a discriminator value — so the discriminator key has to be put back, which is what
     * makes the emitted payload re-readable.
     */
    private function serializeUnion(Schema $schema, mixed $value): mixed
    {
        if (!is_object($value)) {
            return $value;
        }
        $propertyName = $schema->discriminatorPropertyName();
        foreach ($schema->candidates as $discriminatorValue => $candidate) {
            if ($candidate->target === null || !$value instanceof $candidate->target) {
                continue;
            }
            $serialized = $this->serialize($candidate, $value);
            if (!is_array($serialized) || array_key_exists($propertyName, $serialized)) {
                // the candidate already emitted the tag (its schema pins it as a `const`)
                return $serialized;
            }
            return [$propertyName => $discriminatorValue, ...$serialized];
        }
        throw new UnextractableValue(sprintf(
            '"%s" is not one of the candidates of the "%s" union ("%s")',
            $value::class,
            $propertyName,
            implode('", "', array_keys($schema->candidates)),
        ), 1751885503);
    }

    private function serializeObjectStructurally(Schema $schema, mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $result = [];
        foreach ($value as $name => $propertyValue) {
            $result[$name] = isset($schema->properties[$name])
                ? $this->serialize($schema->properties[$name], $propertyValue)
                : $propertyValue;
        }
        return $result;
    }

    private function serializeArrayStructurally(Schema $schema, mixed $value): mixed
    {
        if (!is_array($value) || $schema->items === null) {
            return $value;
        }
        $itemSchema = $schema->items;
        return array_map(fn(mixed $item): mixed => $this->serialize($itemSchema, $item), $value);
    }
}
