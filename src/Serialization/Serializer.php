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
            Kind::Scalar => $value,
        };
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
