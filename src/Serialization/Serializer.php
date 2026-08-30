<?php

declare(strict_types=1);

namespace Neos\Schematic\Serialization;

use Neos\JsonSchema\AnyOfSchema;
use Neos\JsonSchema\ArraySchema;
use Neos\JsonSchema\BooleanSchema;
use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\NullSchema;
use Neos\JsonSchema\NumberSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\OneOfSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Reflection\ClassShape;
use Neos\Schematic\Reflection\Nature;
use Neos\Schematic\Schematic;
use Neos\Schematic\UnextractableValue;

/**
 * Reads an instance back into primitives — the mirror of {@see \Neos\Schematic\Instantiation\Instantiator}, and
 * like it driven by the class alone.
 *
 * **Extraction only.** What comes out here is what the class holds, which is not yet what the document describes:
 * PHP's one `array` type covers both of JSON's structures, so an empty map and an empty list are indistinguishable
 * at this point. {@see \Neos\JsonSchema\Validation\Projection::outbound()} is the second pass that tells them
 * apart from the schema.
 *
 * **The schema says which kind of thing a class is; reflection says where to find it.** `Success{bool $success}`
 * publishing `type: object` is an envelope, `Slug{string $value}` publishing `type: string` is a value object,
 * and their constructors are identical — so the kind comes from the schema, and only *which* property holds the
 * value comes from the class. Reading the kind off the constructor as well ({@see ClassShape}) is the fallback
 * for the positions a schema cannot answer for: a union, an `allOf`, a `$ref`, or no schema at all.
 *
 * **Public properties only.** Construction reaches a private constructor through a
 * closure the class hands over deliberately; going out, the equivalent is a class exposing its state, or writing
 * that `jsonSerialize()`. Reflecting past `private` would be the engine deciding a boundary the class drew does
 * not apply to it.
 *
 * @internal reached through {@see Schematic::serialize()}
 */
final class Serializer
{
    private function __construct() {}

    public static function read(mixed $value, Schema|null $schema = null): mixed
    {
        $schema = self::substantive($schema);
        if (is_array($value)) {
            $items = $schema instanceof ArraySchema ? $schema->itemSchema(0) : null;
            return array_map(
                static fn(mixed $item): mixed => self::read($item, $items instanceof Schema ? $items : null),
                $value,
            );
        }
        if ($value instanceof \UnitEnum) {
            return $value instanceof \BackedEnum ? $value->value : $value->name;
        }
        if (!is_object($value)) {
            return $value;
        }
        $shape = ClassShape::of($value::class);
        return match (self::natureOf($schema) ?? $shape->nature) {
            // an enum is handled above, before any reflection
            Nature::Enum => throw new \LogicException(sprintf('"%s" is not an enum instance', $value::class), 1783600501),
            Nature::Scalar => self::readScalar($value, $shape, $schema),
            Nature::ListOf => self::readList($value, $shape, $schema),
            Nature::Shape => self::readShape($value, $shape, $schema),
        };
    }

    /**
     * What kind of thing the schema says this is, or `null` where it does not say.
     *
     * The schema is the only place the answer can come from for the one case a constructor cannot express: a
     * one-member envelope whose member is a builtin scalar (`Success{bool $success}` -> `{"success": true}`) is
     * written exactly like a scalar-backed value object (`Slug{string $value}` -> `"a-slug"`). It answers the
     * other three the same way, so there is no second rule to keep in step.
     *
     * A composite with no single structural reading - `allOf`, `not`, `$ref` - says nothing, and the class's own
     * {@see ClassShape} decides, as it always did.
     */
    private static function natureOf(Schema|null $schema): Nature|null
    {
        return match (true) {
            $schema instanceof ObjectSchema => Nature::Shape,
            $schema instanceof ArraySchema => Nature::ListOf,
            $schema instanceof StringSchema,
            $schema instanceof IntegerSchema,
            $schema instanceof NumberSchema,
            $schema instanceof BooleanSchema => Nature::Scalar,
            default => null,
        };
    }

    /**
     * A union is unwrapped to its one substantive branch, which is the `anyOf: [<something>, {"type": "null"}]`
     * idiom {@see \Neos\JsonSchema\Nullable} produces - a nullable member is still that member.
     *
     * A genuine multi-branch union cannot be resolved from here: picking a branch means validating the value
     * against it, and the value is still an object at this point. It is left unanswered, and the class decides.
     */
    private static function substantive(Schema|null $schema): Schema|null
    {
        if (!$schema instanceof AnyOfSchema && !$schema instanceof OneOfSchema) {
            return $schema;
        }
        $substantive = [];
        foreach ($schema as $branch) {
            if (!$branch instanceof NullSchema) {
                $substantive[] = $branch;
            }
        }
        return count($substantive) === 1 ? self::substantive($substantive[0]) : $schema;
    }

    private static function readScalar(object $value, ClassShape $shape, Schema|null $schema): mixed
    {
        if ($shape->parameters === []) {
            throw new UnextractableValue(sprintf(
                'The schema of "%s" describes a single value, but the class has no constructor parameter holding one',
                $value::class,
            ), 1783600503);
        }
        return self::read(self::publicProperty($value, $shape->parameters[0]->getName()), $schema);
    }

    /**
     * A collection is read through `Traversable` when it offers one — the class's own answer to "what is in me" —
     * and otherwise from the public property named after its variadic parameter.
     */
    private static function readList(object $value, ClassShape $shape, Schema|null $schema = null): mixed
    {
        $itemSchema = $schema instanceof ArraySchema ? $schema->itemSchema(0) : null;
        $items = $itemSchema instanceof Schema ? $itemSchema : null;
        if ($value instanceof \Traversable) {
            return array_values(array_map(static fn(mixed $item): mixed => self::read($item, $items), iterator_to_array($value)));
        }
        $name = $shape->parameters[0]->getName();
        $held = self::publicProperty($value, $name, sprintf(
            'implement IteratorAggregate, or expose the items as a public property "$%s"',
            $name,
        ));
        if (!is_array($held)) {
            throw new UnextractableValue(sprintf(
                'Expected "%s::$%s" to hold an array of items, got %s — implement IteratorAggregate if the items live elsewhere',
                $value::class,
                $name,
                get_debug_type($held),
            ), 1783600502);
        }
        return array_values(array_map(static fn(mixed $item): mixed => self::read($item, $items), $held));
    }

    /**
     * @return array<string, mixed>
     */
    private static function readShape(object $value, ClassShape $shape, Schema|null $schema = null): array
    {
        $properties = $schema instanceof ObjectSchema ? $schema->properties : null;
        $result = [];
        foreach ($shape->parameters as $parameter) {
            $name = $parameter->getName();
            $result[$name] = self::read(self::publicProperty($value, $name), $properties?->get($name));
        }
        return $result;
    }

    /**
     * Read from outside the class, so `get_object_vars()` sees public *initialized* properties only.
     */
    private static function publicProperty(object $value, string $name, string $remedy = 'expose it as a public property, or write a jsonSerialize() of your own'): mixed
    {
        $properties = get_object_vars($value);
        if (!array_key_exists($name, $properties)) {
            throw new UnextractableValue(sprintf(
                '"%s" does not expose a public property "$%s" (matching its constructor) — %s',
                $value::class,
                $name,
                $remedy,
            ), 1783600503);
        }
        return $properties[$name];
    }
}
