<?php

declare(strict_types=1);

namespace Neos\Schematic\Attributes\Instantiation;

use Neos\Schematic\Instantiation\InstanceFactory;
use Neos\Schematic\Instantiation\Instantiator;
use Neos\Schematic\Schema\Kind;
use Neos\Schematic\Schema\Schema;
use ReflectionClass;

/**
 * Builds a list-based value object by instantiating each coerced item (via the item schema) and passing the items to
 * the target's constructor — spread for a variadic constructor, or as a single array argument otherwise.
 */
final class ListFactory implements InstanceFactory
{
    public function instantiate(Schema $schema, mixed $value, Instantiator $instantiator): mixed
    {
        if ($schema->kind !== Kind::List || $schema->items === null) {
            throw new \LogicException(sprintf('%s requires a list schema', self::class), 1751885104);
        }
        $itemSchema = $schema->items;
        $target = $schema->target;
        if ($target === null) {
            throw new \LogicException(sprintf('%s requires the schema node to have a target', self::class), 1751885105);
        }
        if (!is_array($value)) {
            throw new \LogicException(sprintf('%s expects an array value, got %s', self::class, get_debug_type($value)), 1751885106);
        }
        $items = array_values(array_map(
            fn(mixed $item): mixed => $instantiator->instantiate($itemSchema, $item),
            $value,
        ));
        /** @var ReflectionClass<object> $reflectionClass */
        $reflectionClass = new ReflectionClass($target);
        $parameters = $reflectionClass->getConstructor()?->getParameters() ?? [];
        $variadic = $parameters !== [] && $parameters[0]->isVariadic();
        return Construction::construct($reflectionClass, $variadic ? $items : [$items]);
    }
}
