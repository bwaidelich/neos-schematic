<?php

declare(strict_types=1);

namespace Neos\Schematic\Attributes\Instantiation;

use Neos\Schematic\Instantiation\InstanceFactory;
use Neos\Schematic\Instantiation\Instantiator;
use Neos\Schematic\Schema\Schema;
use ReflectionClass;

/**
 * Builds a scalar-based value object (string/integer/float wrapped in a class) by passing the single coerced scalar
 * to the target's constructor.
 */
final class ValueFactory implements InstanceFactory
{
    public function instantiate(Schema $schema, mixed $value, Instantiator $instantiator): mixed
    {
        $target = $schema->target;
        if ($target === null) {
            throw new \LogicException(sprintf('%s requires the schema node to have a target', self::class), 1751885100);
        }
        /** @var ReflectionClass<object> $reflectionClass */
        $reflectionClass = new ReflectionClass($target);
        return Construction::construct($reflectionClass, [$value]);
    }
}
