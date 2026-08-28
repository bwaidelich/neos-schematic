<?php

declare(strict_types=1);

namespace Neos\Schematic;

use Neos\JsonSchema\ArraySchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Reflection\ClassShape;
use Neos\Schematic\Reflection\Nature;

/**
 * Asks whether a class and the schema it publishes still say the same thing.
 *
 * A schema from {@see Discovery\AutoDiscoveringSchema} cannot disagree with its class: it is *read off* the same
 * {@see ClassShape} that {@see Instantiation\Instantiator} builds by. A **hand-written** `schema()` can, and the
 * disagreement is invisible until it bites — validation passes, and construction then fails with a `TypeError` for
 * a null the constructor never accepted, or a `\LogicException` for a parameter the schema forgot to require.
 * Serialization is worse: it emits whatever the class holds, and nothing compares that to the published document.
 *
 * So this asks the questions a schema cannot answer about itself, from the class side. It is **not** a runtime
 * guard: the answer is the same for the life of the process, so it belongs in a test —
 *
 *     self::assertSame([], Conformance::check(Post::class));
 *
 * — where a disagreement is what it actually is, a programming error, rather than a cost paid on every request.
 *
 * Every question is put to the schema by *validating* against it rather than by reading its keywords, so a
 * hand-written `anyOf`, a `oneOf` or anything else answers exactly as the validator would at runtime.
 */
final class Conformance
{
    private function __construct() {}

    /**
     * Everything the class and its schema disagree about, in the order the constructor names it. Empty when they
     * say the same thing.
     *
     * The question is **"can this class be built from what its schema accepts, and does it publish what it
     * takes?"** — so it is asked of the constructor, and it is the right question only for a type that arrives
     * from outside. A type that is only ever *emitted* — a schema published for documentation, a hand-written
     * `jsonSerialize()`, nothing ever mapping a payload onto it — answers it meaninglessly, and is held to
     * {@see self::checkSerialization()} instead.
     *
     * @param class-string $className
     * @return list<string>
     * @throws SchemaNotProvided if the class does not provide a schema
     */
    public static function check(string $className): array
    {
        $schema = Schematic::schemaFor($className);
        $shape = ClassShape::of($className);
        return match ($shape->nature) {
            Nature::Enum => self::enum($className, $schema),
            Nature::Scalar => self::scalar($shape, $schema),
            Nature::ListOf => self::listOf($shape, $schema),
            Nature::Shape => self::shape($shape, $schema),
        };
    }

    /**
     * The other half, which needs an instance: what the class *emits* judged against what it *publishes*. That is
     * the whole check for an output-only type, and the other half of it for a type that also arrives from outside:
     * a value that never went through validation — a class with a public constructor, built with `new` — can hold
     * anything its schema rejects.
     *
     * @return list<string>
     * @throws SchemaNotProvided if the class does not provide a schema
     */
    public static function checkSerialization(object $instance): array
    {
        // What the class actually puts on the wire, which is not always what Schematic::serialize() reads: a
        // class with a jsonSerialize() of its own emits *that*, and it is the emitted form the schema describes.
        $primitives = $instance instanceof \JsonSerializable ? $instance : Schematic::serialize($instance);
        $encoded = json_decode((string) json_encode($primitives), true);
        $result = Schematic::schemaFor($instance::class)->validate($encoded);
        return $result->valid ? [] : array_map(
            static fn(\Neos\JsonSchema\Validation\Issue $issue): string => sprintf(
                'serialized to something its own schema rejects at "%s": %s',
                $issue->pathAsString(),
                $issue->message,
            ),
            $result->issues->toArray(),
        );
    }

    /**
     * @param class-string $className
     * @return list<string>
     */
    private static function enum(string $className, Schema $schema): array
    {
        if (!is_subclass_of($className, \UnitEnum::class)) {
            return [sprintf('"%s" reads as an enum but is not one', $className)];
        }
        $disagreements = [];
        foreach ($className::cases() as $case) {
            $value = $case instanceof \BackedEnum ? $case->value : $case->name;
            if (!$schema->validate($value)->valid) {
                $disagreements[] = sprintf('case %s serializes to %s, which the schema rejects', $case->name, json_encode($value));
            }
        }
        return $disagreements;
    }

    /**
     * @return list<string>
     */
    private static function scalar(ClassShape $shape, Schema $schema): array
    {
        if ($schema instanceof ObjectSchema || $schema instanceof ArraySchema) {
            // the class is one value; the schema describes a structure, so nothing about the two lines up
            return ['the class is a single value, but its schema describes a structure'];
        }
        return self::parameter($shape->parameters[0], $schema, required: true);
    }

    /**
     * @return list<string>
     */
    private static function listOf(ClassShape $shape, Schema $schema): array
    {
        if (!$schema instanceof ArraySchema || !$schema->items instanceof Schema) {
            return ['the class is a collection, but its schema is not an array schema with items'];
        }
        return self::parameter($shape->parameters[0], $schema->items, required: true);
    }

    /**
     * @return list<string>
     */
    private static function shape(ClassShape $shape, Schema $schema): array
    {
        if (!$schema instanceof ObjectSchema || $schema->properties === null) {
            return ['the class is a shape, but its schema is not an object schema with properties'];
        }
        $required = $schema->required ?? [];
        $disagreements = [];
        foreach ($shape->parameters as $parameter) {
            $name = $parameter->getName();
            $propertySchema = $schema->properties->get($name);
            if ($propertySchema === null) {
                $disagreements[] = sprintf('$%s: the constructor names it, the schema has no such property', $name);
                continue;
            }
            $isRequired = in_array($name, $required, true);
            if (!$parameter->isDefaultValueAvailable() && !$isRequired) {
                // validation would pass without it, and construction would then have nothing to pass
                $disagreements[] = sprintf('$%s: the constructor has no default for it, but the schema does not require it', $name);
            }
            $disagreements = [...$disagreements, ...self::parameter($parameter, $propertySchema, $isRequired)];
        }
        foreach ($schema->properties->names() as $name) {
            if ($shape->parameter($name) === null) {
                // validation lets it through and the instantiator drops it, so it is in the document and nowhere else
                $disagreements[] = sprintf('"%s": the schema publishes it, the constructor names no such parameter', $name);
            }
        }
        return $disagreements;
    }

    /**
     * One constructor parameter against the schema of the value it receives: does the schema let through a null
     * the parameter would refuse, and does it let through the wrong primitive type entirely?
     *
     * @return list<string>
     */
    private static function parameter(\ReflectionParameter $parameter, Schema $schema, bool $required): array
    {
        $name = $parameter->getName();
        $type = $parameter->getType();
        if (!$type instanceof \ReflectionNamedType) {
            return [sprintf('$%s: not a single named type, so nothing here can be compared to a schema', $name)];
        }
        $disagreements = [];
        $schemaAcceptsNull = $schema->validate(null)->valid;
        if ($schemaAcceptsNull && !$type->allowsNull()) {
            $disagreements[] = sprintf('$%s: the schema accepts null, the constructor does not', $name);
        }
        if (!$schemaAcceptsNull && $type->allowsNull() && $required) {
            // not a crash, but the class offers an absence the document says cannot arrive
            $disagreements[] = sprintf('$%s: the constructor accepts null, the schema does not', $name);
        }
        $sample = match ($type->isBuiltin() ? $type->getName() : null) {
            'string' => '',
            'int' => 0,
            'float' => 0.0,
            'bool' => false,
            // a class-typed parameter is described by that class's own schema, which checks itself
            default => null,
        };
        if ($sample !== null && in_array('invalid_type', $schema->validate($sample)->issues->codes(), true)) {
            $disagreements[] = sprintf('$%s: typed %s, but the schema rejects that type outright', $name, $type->getName());
        }
        return $disagreements;
    }
}
