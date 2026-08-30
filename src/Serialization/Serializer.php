<?php

declare(strict_types=1);

namespace Neos\Schematic\Serialization;

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
 * at this point. {@see Projection} is the second pass that tells them apart from the schema.
 *
 * **Public properties only.** Construction reaches a private constructor through a closure the class hands over
 * deliberately; going out, the equivalent is a class exposing its state, or writing a `jsonSerialize()` of its
 * own. Reflecting past `private` would be the engine deciding a boundary the class drew does not apply to it.
 *
 * @internal reached through {@see Schematic::serialize()}
 */
final class Serializer
{
    private function __construct() {}

    public static function read(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(static fn(mixed $item): mixed => self::read($item), $value);
        }
        if ($value instanceof \UnitEnum) {
            return $value instanceof \BackedEnum ? $value->value : $value->name;
        }
        if (!is_object($value)) {
            return $value;
        }
        $shape = ClassShape::of($value::class);
        return match ($shape->nature) {
            // an enum is handled above, before any reflection
            Nature::Enum => throw new \LogicException(sprintf('"%s" is not an enum instance', $value::class), 1783600501),
            Nature::Scalar => self::read(self::publicProperty($value, $shape->parameters[0]->getName())),
            Nature::ListOf => self::readList($value, $shape),
            Nature::Shape => self::readShape($value, $shape),
        };
    }

    /**
     * A collection is read through `Traversable` when it offers one — the class's own answer to "what is in me" —
     * and otherwise from the public property named after its variadic parameter.
     */
    private static function readList(object $value, ClassShape $shape): mixed
    {
        if ($value instanceof \Traversable) {
            return array_values(array_map(static fn(mixed $item): mixed => self::read($item), iterator_to_array($value)));
        }
        $name = $shape->parameters[0]->getName();
        $items = self::publicProperty($value, $name, sprintf(
            'implement IteratorAggregate, or expose the items as a public property "$%s"',
            $name,
        ));
        if (!is_array($items)) {
            throw new UnextractableValue(sprintf(
                'Expected "%s::$%s" to hold an array of items, got %s — implement IteratorAggregate if the items live elsewhere',
                $value::class,
                $name,
                get_debug_type($items),
            ), 1783600502);
        }
        return array_values(array_map(static fn(mixed $item): mixed => self::read($item), $items));
    }

    /**
     * @return array<string, mixed>
     */
    private static function readShape(object $value, ClassShape $shape): array
    {
        $result = [];
        foreach ($shape->parameters as $parameter) {
            $name = $parameter->getName();
            $result[$name] = self::read(self::publicProperty($value, $name));
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
