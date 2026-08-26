<?php

declare(strict_types=1);

namespace Neos\Schematic\Attributes\Serialization;

use Neos\Schematic\Attributes\Instantiation\ListFactory;
use Neos\Schematic\Schema\Kind;
use Neos\Schematic\Schema\Schema;
use Neos\Schematic\Serialization\Serializer;
use Neos\Schematic\Serialization\UnextractableValue;
use Neos\Schematic\Serialization\ValueExtractor;
use Traversable;

/**
 * Reads a list-based value object back into a list — the mirror of {@see ListFactory}, which hands the items to the
 * target's constructor (spread for a variadic one, as a single array otherwise).
 *
 * Because a variadic constructor parameter cannot be promoted, the items are not always reachable under the
 * parameter's name, so two routes are tried: iterate the target if it is `Traversable`, otherwise read the property
 * named after its first constructor parameter. Neither working is a mismatch between the class and its schema, and
 * says so rather than emitting an empty list.
 */
final class ListExtractor implements ValueExtractor
{
    public function extract(Schema $schema, mixed $value, Serializer $serializer): mixed
    {
        if ($schema->kind !== Kind::List || $schema->items === null) {
            throw new \LogicException(sprintf('%s requires a list schema', self::class), 1751885307);
        }
        $itemSchema = $schema->items;
        $items = $this->items($value);
        return array_values(array_map(
            fn(mixed $item): mixed => $serializer->serialize($itemSchema, $item),
            $items,
        ));
    }

    /**
     * @return array<mixed>
     */
    private function items(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof Traversable) {
            return iterator_to_array($value);
        }
        if (!is_object($value)) {
            throw new UnextractableValue(sprintf('%s expected a list-based value object or array, got %s', self::class, get_debug_type($value)), 1751885308);
        }
        $parameters = (new \ReflectionClass($value))->getConstructor()?->getParameters() ?? [];
        if ($parameters === []) {
            throw new UnextractableValue(sprintf(
                '%s cannot read the items of "%s": it is not Traversable and has no constructor parameter to read back',
                self::class,
                $value::class,
            ), 1751885309);
        }
        $items = PropertyAccess::read($value, $parameters[0]->getName(), self::class);
        if (!is_array($items)) {
            throw new UnextractableValue(sprintf(
                '%s expected "%s::$%s" to hold an array of items, got %s — implement IteratorAggregate if the items live elsewhere',
                self::class,
                $value::class,
                $parameters[0]->getName(),
                get_debug_type($items),
            ), 1751885310);
        }
        return $items;
    }
}
