<?php

declare(strict_types=1);

namespace Neos\Schematic\Serialization;

use Neos\JsonSchema\AllOfSchema;
use Neos\JsonSchema\AnyOfSchema;
use Neos\JsonSchema\ArraySchema;
use Neos\JsonSchema\NotSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\OneOfSchema;
use Neos\JsonSchema\ReferenceSchema;
use Neos\JsonSchema\Schema;

/**
 * The primitives {@see Serializer} extracted, shaped the way the schema describes them — the outbound mirror of
 * {@see \Neos\JsonSchema\Validation\Validator}'s projection step.
 *
 * **Why a schema is needed at all on the way out.** PHP has one `array` type and JSON has two, so a class alone
 * cannot say whether `[]` is an empty list or an empty map: `["entries" => []]` encodes as `{"entries":[]}` even
 * where the schema published for it says `"entries"` is an object. Nothing in the value can answer that, and the
 * only thing that can is the schema — which is exactly how the *inbound* direction already reads `[]`
 * ({@see \Neos\JsonSchema\Validation\Validator::projectObject()}). Reading being schema-directed and writing not
 * being so is what made `map()` followed by `serialize()` lose data.
 *
 * **Two passes, not one.** Extraction needs the class (an enum's backing value, a scalar value object's single
 * member, a collection's `Traversable`) and knows nothing of the document; projection needs the schema and
 * nothing of the class. Interleaving them would put both rule sets in one recursion and leave the walk with
 * nowhere to go at an {@see AllOfSchema}, a {@see NotSchema} or a {@see ReferenceSchema} — which have no single
 * structural reading, and which this pass therefore hands back untouched, as the Validator does.
 *
 * @internal reached through {@see \Neos\Schematic\Schematic::serialize()}
 */
final class Projection
{
    private function __construct() {}

    public static function apply(Schema $schema, mixed $value): mixed
    {
        if ($schema instanceof OneOfSchema || $schema instanceof AnyOfSchema) {
            return self::branches($schema, $value);
        }
        if ($schema instanceof ObjectSchema) {
            return self::object($schema, $value);
        }
        if ($schema instanceof ArraySchema) {
            return self::array($schema, $value);
        }
        return $value;
    }

    /**
     * An object: `[]` is the empty *map* the schema says it is, and the members the schema names are descended
     * into, in the order it names them.
     *
     * Keys the schema does not name follow, kept rather than dropped — even under `additionalProperties: false`,
     * where the Validator drops them inbound. Dropping on the way *in* discards what a client sent that the
     * document never promised to read; dropping on the way *out* would discard what this API's own class chose to
     * emit, silently, which is a bug to report ({@see \Neos\Schematic\Conformance::checkSerialization()}) rather
     * than one to hide.
     */
    private static function object(ObjectSchema $schema, mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value === []) {
            return new \stdClass();
        }
        if ($schema->properties === null) {
            // a free-form map: its members are described by nothing, so there is nothing here to shape
            return $value;
        }
        $projected = [];
        foreach ($schema->properties as $name => $propertySchema) {
            if (array_key_exists($name, $value)) {
                $projected[$name] = self::apply($propertySchema, $value[$name]);
            }
        }
        return $projected + $value;
    }

    private static function array(ArraySchema $schema, mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $projected = [];
        foreach ($value as $index => $item) {
            $itemSchema = is_int($index) ? self::itemSchema($schema, $index) : null;
            $projected[$index] = $itemSchema === null ? $item : self::apply($itemSchema, $item);
        }
        return $projected;
    }

    /**
     * A union is shaped by the branch the value matches — the first one that does, since a value cannot be two
     * shapes at once, and since `[]` matching both an object and an array branch is the very ambiguity being
     * resolved here. Matching none leaves it untouched: {@see \Neos\Schematic\Conformance} is where that is
     * reported, not here.
     */
    private static function branches(AnyOfSchema|OneOfSchema $schema, mixed $value): mixed
    {
        foreach ($schema as $branch) {
            if ($branch->validate($value)->valid) {
                return self::apply($branch, $value);
            }
        }
        return $value;
    }

    /**
     * The schema an item at the given index is described by: `prefixItems` positionally, `items` for the rest —
     * the same reading {@see \Neos\JsonSchema\Validation\Validator} takes inbound.
     */
    private static function itemSchema(ArraySchema $schema, int $index): Schema|null
    {
        $prefix = $schema->prefixItems !== null ? iterator_to_array($schema->prefixItems, false) : [];
        if ($index < count($prefix)) {
            return $prefix[$index];
        }
        return $schema->items instanceof Schema ? $schema->items : null;
    }
}
