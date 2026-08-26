<?php

declare(strict_types=1);

namespace Neos\Schematic\Attributes\Instantiation;

use Neos\Schematic\Coercion\InvalidValue;
use Neos\Schematic\Instantiation\InstanceFactory;
use Neos\Schematic\Instantiation\Instantiator;
use Neos\Schematic\Schema\Kind;
use Neos\Schematic\Schema\Schema;
use ReflectionClass;

/**
 * Builds a shape (an object with named properties) by mapping each coerced property value to the corresponding
 * constructor parameter (in declaration order), filling defaults for parameters not present in the input, and
 * recursing into child schemas via the {@see Instantiator}.
 */
final class ShapeFactory implements InstanceFactory
{
    public function instantiate(Schema $schema, mixed $value, Instantiator $instantiator): mixed
    {
        if ($schema->kind !== Kind::Object) {
            throw new \LogicException(sprintf('%s requires an object schema', self::class), 1751885101);
        }
        $target = $schema->target;
        if ($target === null) {
            throw new \LogicException(sprintf('%s requires the schema node to have a target', self::class), 1751885102);
        }
        if (!is_array($value)) {
            throw new \LogicException(sprintf('%s expects an array value, got %s', self::class, get_debug_type($value)), 1751885103);
        }
        /** @var ReflectionClass<object> $reflectionClass */
        $reflectionClass = new ReflectionClass($target);
        $constructor = $reflectionClass->getConstructor();
        $arguments = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            if (isset($schema->properties[$name]) && array_key_exists($name, $value)) {
                $arguments[] = $instantiator->instantiate($schema->properties[$name], $value[$name]);
            } elseif ($parameter->isVariadic()) {
                break;
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } elseif ($parameter->allowsNull()) {
                $arguments[] = null;
            } else {
                throw new InvalidValue(sprintf('Missing value for required parameter "%s"', $name), [$name]);
            }
        }
        return Construction::construct($reflectionClass, $arguments);
    }
}
