<?php

declare(strict_types=1);

namespace Neos\Schematic\Instantiation;

use Neos\JsonSchema\AnySchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Reflection\ClassShape;
use Neos\Schematic\Reflection\Nature;
use Neos\Schematic\SchemaNotProvided;
use Neos\Schematic\Schematic;

/**
 * Builds an instance from **already validated** primitives — the schema is still needed, but only to say what
 * kind of thing the class is.
 *
 * **The constructor is the way in, whatever its visibility.** A class opts into being built by implementing
 * `ProvidesSchema`; from there its `__construct()` is called with the primitives its parameters name — a public
 * one directly, a non-public one through reflection. The engine deliberately does *not* call `from*()` named
 * constructors: a named constructor is how a *caller* writes the class's invariant down, and it in turn calls
 * {@see Schematic::instanciate()}, so calling it from here would validate the same value twice per level of
 * nesting and turn the obvious `fromString()` into unbounded recursion.
 *
 * The consequence, and it is the intended trade: **a rule that lives in a named constructor body rather than in
 * the schema or the constructor is not enforced on the way in.** That is the same rule the package states for
 * invariants generally — the schema is the validator, the constructor is the last word, and anything else is not
 * part of the contract.
 *
 * Descent into a class-typed parameter goes back through {@see Schematic::instanciate()}, so a nested value object
 * is validated against *its own* schema. There is no second, construct-only path to keep in step with this one.
 *
 * @internal reached through {@see Schematic::instanciate()}
 */
final class Instantiator
{
    private function __construct() {}

    /**
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    public static function build(string $className, mixed $value, Schema|null $schema = null): object
    {
        $shape = ClassShape::of($className);
        // an enum is one whatever a schema says; only the other three are open to question
        if ($shape->nature === Nature::Enum) {
            $case = self::enumCase($className, $value);
            // a case of the enum that was asked for, which is what makes it the T the caller named
            return $case instanceof $className
                ? $case
                : throw new \LogicException(sprintf('"%s" resolved to a case of %s', $className, $case::class), 1783600410);
        }
        // the same rule the way back out uses — see Serializer::natureOf() for why each arm is what it is
        $nature = $schema === null || $schema instanceof AnySchema
            ? $shape->nature
            : Nature::of($schema) ?? throw new \LogicException(sprintf(
                'The schema published for "%s" has no single structural reading — a union whose branches are '
                . 'different kinds of thing, an "allOf" or a "$ref" — so it cannot say what to build from these '
                . 'primitives. Publish the type under a schema that says which.',
                $className,
            ), 1783600412);
        $arguments = self::arguments($nature, $shape->parameters, $value, $className, $schema);
        // named arguments for a shape, so declaration order stops mattering; positional for the rest
        $positional = $nature === Nature::Shape ? $arguments : array_values($arguments);
        /** @var \ReflectionClass<T> $reflectionClass */
        $reflectionClass = new \ReflectionClass($className);
        $constructor = $reflectionClass->getConstructor();
        if ($constructor === null || $constructor->isPublic()) {
            return $reflectionClass->newInstanceArgs($positional);
        }
        // A non-public constructor is not a fence against this engine — the class asked to be built by
        // implementing ProvidesSchema — it is a fence against `new` from anywhere else, and it stays one.
        $instance = $reflectionClass->newInstanceWithoutConstructor();
        $constructor->invokeArgs($instance, $positional);
        return $instance;
    }

    /**
     * The constructor arguments, keyed by parameter name for a shape (so they can be passed as named arguments and
     * declaration order stops mattering) and positional for the other natures.
     *
     * @param list<\ReflectionParameter> $parameters
     * @param class-string $className
     * @return array<string, mixed>
     */
    private static function arguments(Nature $nature, array $parameters, mixed $value, string $className, Schema|null $schema): array
    {
        if ($nature === Nature::Scalar) {
            if ($parameters === []) {
                throw new \LogicException(sprintf('Expected "%s" to have a constructor parameter to hold a single value', $className), 1783600411);
            }
            return ['value' => $value];
        }
        if ($nature === Nature::ListOf) {
            if (!is_array($value)) {
                throw new \LogicException(sprintf('Expected a list to build "%s", got %s', $className, get_debug_type($value)), 1783600403);
            }
            $itemType = self::classType($parameters[0] ?? null);
            $items = [];
            foreach ($value as $index => $item) {
                $items[(string) $index] = $itemType === null ? $item : Schematic::instanciate($itemType, $item)->valueOrThrow();
            }
            return $items;
        }
        if (!is_array($value)) {
            throw new \LogicException(sprintf('Expected an array to build "%s", got %s', $className, get_debug_type($value)), 1783600404);
        }
        $arguments = [];
        foreach ($parameters as $parameter) {
            $name = $parameter->getName();
            if (!array_key_exists($name, $value)) {
                if ($parameter->isDefaultValueAvailable()) {
                    continue;
                }
                // the schema declares a parameter without a default as required, so validation already rejected
                // an input missing it
                throw new \LogicException(sprintf('Missing value for required parameter "$%s" of "%s"', $name, $className), 1783600405);
            }
            $arguments[$name] = self::argument($parameter, $value[$name]);
        }
        return $arguments;
    }

    private static function argument(\ReflectionParameter $parameter, mixed $value): mixed
    {
        // a null needs no type to build it, so it is answered before the parameter's own type is asked for
        if ($value === null) {
            return $value;
        }
        $className = self::classType($parameter);
        if ($className === null) {
            return $value;
        }
        return Schematic::instanciate($className, $value)->valueOrThrow();
    }

    /**
     * The class a parameter is typed by, or `null` when it is a builtin (`?Foo` counts as `Foo`).
     *
     * A class-typed parameter is a value to be built, and building goes through the class's own schema — so one
     * that owns none is refused here, in the same terms {@see Schematic::schemaFor()} would refuse it a frame
     * later. Saying so at this edge is what lets the descent be typed by the opt-in itself.
     *
     * @return class-string<ProvidesSchema>|null
     * @throws SchemaNotProvided if the parameter is typed by a class that provides no schema
     */
    private static function classType(\ReflectionParameter|null $parameter): string|null
    {
        $type = $parameter?->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }
        /** @var class-string $name */
        $name = $type->getName();
        if (!is_a($name, ProvidesSchema::class, true)) {
            throw SchemaNotProvided::forClass($name);
        }
        return $name;
    }

    /**
     * Enum cases are singletons, so they are looked up rather than built: a *backed* enum by its backing value,
     * a *pure* one by its case name — mirroring what the schema published for it.
     *
     * @param class-string $className
     */
    private static function enumCase(string $className, mixed $value): \UnitEnum
    {
        if (!is_subclass_of($className, \UnitEnum::class)) {
            throw new \LogicException(sprintf('"%s" is not an enum', $className), 1783600406);
        }
        if (is_subclass_of($className, \BackedEnum::class)) {
            if (!is_string($value) && !is_int($value)) {
                throw new \LogicException(sprintf('Expected a string or integer to resolve a case of "%s", got %s', $className, get_debug_type($value)), 1783600407);
            }
            return $className::tryFrom($value)
                ?? throw new \LogicException(sprintf('Value "%s" is not a case of enum "%s"', $value, $className), 1783600408);
        }
        foreach ($className::cases() as $case) {
            if ($case->name === $value) {
                return $case;
            }
        }
        throw new \LogicException(sprintf('Value "%s" is not a case of enum "%s"', is_scalar($value) ? (string) $value : get_debug_type($value), $className), 1783600409);
    }
}
