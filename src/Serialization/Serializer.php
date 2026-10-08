<?php

declare(strict_types=1);

namespace Neos\Schematic\Serialization;

use Neos\JsonSchema\AnyOfSchema;
use Neos\JsonSchema\AnySchema;
use Neos\JsonSchema\ArraySchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\OneOfSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;
use Neos\Schematic\Exception\UnextractableValue;
use Neos\Schematic\Reflection\ClassShape;
use Neos\Schematic\Reflection\Nature;
use Neos\Schematic\Schematic;

/**
 * Reads an instance back into primitives — the mirror of {@see \Neos\Schematic\Instantiation\Instantiator}.
 *
 * **One thing the class cannot answer, and the schema can.** PHP's one `array` type covers both of JSON's
 * structures, so `[]` alone cannot say whether it was an empty map or an empty list — and neither can the value
 * holding it. The schema can, and it is the only thing that can, which is why an empty array is emitted as `{}`
 * exactly where the schema being read against calls it an object. That is the *whole* of what the schema decides
 * out here: key order is nothing JSON defines, and a key the class emits is a key the document gets, whether or
 * not the schema names it — though a class and its schema disagreeing about *which* members exist is caught here
 * and raised, since nothing downstream would ever notice.
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
 * Reached through {@see Schematic::serialize()} for the common case — a value read under the schema its own class
 * publishes. This is the way in for the other case: a value read under a schema that is *not* its class's own — a
 * declared type, a union branch, a member nothing describes — which is what the schema parameter is for.
 */
final class Serializer
{
    private function __construct() {}

    public static function serialize(mixed $value, Schema|null $schema = null): mixed
    {
        if (is_array($value)) {
            return self::serializeArray($value, $schema);
        }
        if ($value instanceof \UnitEnum) {
            return $value instanceof \BackedEnum ? $value->value : $value->name;
        }
        if (!is_object($value)) {
            return $value;
        }
        $shape = ClassShape::of($value::class);
        $schema = self::branchPublishedBy($value::class, $schema);
        return match (self::natureOf($schema, $shape, $value::class)) {
            // an enum is handled above, before any reflection
            Nature::Enum => throw new \LogicException(sprintf('"%s" is not an enum instance', $value::class), 1783600501),
            Nature::Scalar => self::serializeScalar($value, $shape, $schema),
            Nature::ListOf => self::serializeList($value, $shape, $schema),
            Nature::Shape => self::serializeShape($value, $shape, $schema),
        };
    }

    /**
     * Primitives that are already an array: nothing to extract, so this only descends — and answers the one
     * question the value cannot, where the schema being read against says the empty array is an object.
     *
     * @param array<array-key, mixed> $value
     */
    private static function serializeArray(array $value, Schema|null $schema): mixed
    {
        if ($schema instanceof AnyOfSchema || $schema instanceof OneOfSchema) {
            // an empty array matching both an object branch and a list branch is the very ambiguity being settled
            // here, so the branch the value matches is the one it is read under
            $schema = self::branchFor($schema, $value);
        }
        if ($schema instanceof ArraySchema) {
            $read = [];
            foreach ($value as $index => $item) {
                $itemSchema = is_int($index) ? $schema->itemSchema($index) : null;
                $read[] = self::serialize($item, $itemSchema instanceof Schema ? $itemSchema : null);
            }
            return $read;
        }
        if ($schema instanceof ObjectSchema) {
            if ($value === []) {
                // the one thing the value cannot say about itself, and the schema just said it
                return new \stdClass();
            }
            $read = [];
            foreach ($value as $key => $item) {
                $read[$key] = self::serialize($item, is_string($key) ? $schema->properties?->get($key) : null);
            }
            return $read;
        }
        // nothing describes these members – a free-form map's values, an `allOf`, no schema at all – so there is
        // nothing here to decide, and guessing is the mistake this avoids
        return array_map(static fn(mixed $item): mixed => self::serialize($item), $value);
    }

    /**
     * The branch of a union an *instance* is read under: the one its own class publishes.
     *
     * A union has to be narrowed to one branch before its members mean anything — which branch a value's empty
     * array sits under is what says whether it goes out as `{}` or `[]`. For primitives that question is settled
     * by validating them ({@see self::branchFor()}), but an instance is not primitives yet, and asking the class
     * is both cheaper and exact: a type published in a `oneOf` puts *its* schema in there — its own, or the one
     * derived on its behalf ({@see Schematic::schemaOf()}) — so the branch that is its schema is the branch it is.
     *
     * A class that has no schema, or one no branch carries, leaves the union as it was — {@see self::natureOf()}
     * then answers from it if the branches agree on their kind, and refuses if they do not.
     *
     * @param class-string $className
     */
    private static function branchPublishedBy(string $className, Schema|null $schema): Schema|null
    {
        if (!$schema instanceof AnyOfSchema && !$schema instanceof OneOfSchema) {
            return $schema;
        }
        if (!is_a($className, ProvidesSchema::class, true) && !ClassShape::of($className)->publiclyConstructible) {
            return $schema;
        }
        $published = Schematic::schemaOf($className);
        foreach ($schema as $branch) {
            // identity first: a class memoizes its schema, so the branch usually *is* the very same object
            if ($branch === $published || $branch == $published) {
                return $branch;
            }
        }
        return $schema;
    }

    /**
     * The first branch the value satisfies, or `null` when it satisfies none — a value cannot be two shapes at once,
     * and one that is no shape the union publishes has no branch to be read under.
     *
     * @param array<array-key, mixed> $value
     */
    private static function branchFor(AnyOfSchema|OneOfSchema $schema, array $value): Schema|null
    {
        foreach ($schema as $branch) {
            if ($branch->validate($value)->valid) {
                return $branch;
            }
        }
        return null;
    }

    /**
     * The kind to read this value as — and the one place the two sources meet, so the rule is written out here
     * rather than left to a `??`:
     *
     * - the schema makes a structural claim: it wins, always. It is the published contract, and it is the only
     *   one of the two that can tell a one-member envelope from the value it wraps.
     * - the schema makes *no* claim — there is none, or it is {@see AnySchema} — the class is not overruling
     *   anything by answering, it is the only source there is.
     * - the schema makes a claim that has no single structural reading — branches that disagree about their kind,
     *   an `allOf`, a `$ref`: **an error**. Falling back to the class here would silently pick the loser of a
     *   real disagreement, which is exactly the bug this whole mechanism exists to prevent.
     */
    private static function natureOf(Schema|null $schema, ClassShape $shape, string $className): Nature
    {
        if ($schema === null || $schema instanceof AnySchema) {
            return $shape->nature;
        }
        return Nature::fromSchema($schema) ?? throw new UnextractableValue(sprintf(
            'The schema published for "%s" here has no single structural reading — a union whose branches are '
            . 'different kinds of thing, an "allOf" or a "$ref" — so it cannot say whether the value is an object, '
            . 'a list or a single value. Publish this member under a schema that says which.',
            $className,
        ), 1783600504);
    }

    private static function serializeScalar(object $value, ClassShape $shape, Schema|null $schema): mixed
    {
        if ($shape->parameters === []) {
            throw new UnextractableValue(sprintf(
                'The schema of "%s" describes a single value, but the class has no constructor parameter holding one',
                $value::class,
            ), 1783600503);
        }
        return self::serialize(self::publicProperty($value, $shape->parameters[0]->getName()), $schema);
    }

    /**
     * A collection is read through `Traversable` when it offers one — the class's own answer to "what is in me" —
     * and otherwise from the public property named after its variadic parameter.
     */
    private static function serializeList(object $value, ClassShape $shape, Schema|null $schema = null): mixed
    {
        $itemSchema = $schema instanceof ArraySchema ? $schema->itemSchema(0) : null;
        $items = $itemSchema instanceof Schema ? $itemSchema : null;
        if ($value instanceof \Traversable) {
            return array_values(array_map(static fn(mixed $item): mixed => self::serialize($item, $items), iterator_to_array($value)));
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
        return array_values(array_map(static fn(mixed $item): mixed => self::serialize($item, $items), $held));
    }

    /**
     * The one place the class and the schema it publishes are both in hand, so it is the one place their
     * *disagreement* can be caught — and it is caught rather than emitted.
     *
     * Nothing downstream would notice: a member the document never described goes out as an extra field, and one
     * the document promised is simply missing from the body. Both are bugs in the API rather than in the request,
     * and both are silent — the reason to raise them here, where they are free to see, is that there is no later
     * point at which anything compares the two.
     *
     * @return array<string, mixed>|\stdClass
     */
    private static function serializeShape(object $value, ClassShape $shape, Schema|null $schema = null): array|\stdClass
    {
        $properties = $schema instanceof ObjectSchema ? $schema->properties : null;
        if ($properties !== null) {
            self::agreeOnMembers($value::class, $shape, $properties);
        }
        $result = [];
        foreach ($shape->parameters as $parameter) {
            $name = $parameter->getName();
            $result[$name] = self::serialize(self::publicProperty($value, $name), $properties?->get($name));
        }
        // a class holding no state at all is still an object, and `[]` would go out as a list
        return $result === [] ? new \stdClass() : $result;
    }

    /**
     * The members the class emits are the members its schema publishes — checked by name, in both directions,
     * which is all a schema and a constructor can be compared on without judging a value.
     *
     * @param class-string $className
     */
    private static function agreeOnMembers(string $className, ClassShape $shape, ObjectProperties $properties): void
    {
        foreach ($shape->parameters as $parameter) {
            if ($properties->get($parameter->getName()) === null) {
                throw new UnextractableValue(sprintf(
                    '"%s" would emit "$%s", which the schema it publishes does not describe — add the property to '
                    . 'the schema, or stop emitting it',
                    $className,
                    $parameter->getName(),
                ), 1783600505);
            }
        }
        foreach ($properties->names() as $name) {
            if ($shape->parameter($name) === null) {
                throw new UnextractableValue(sprintf(
                    '"%s" publishes a property "%s" its constructor names nowhere, so nothing will ever emit it — '
                    . 'remove it from the schema, or take it as a constructor parameter',
                    $className,
                    $name,
                ), 1783600506);
            }
        }
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
