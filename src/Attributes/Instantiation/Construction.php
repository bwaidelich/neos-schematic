<?php

declare(strict_types=1);

namespace Neos\Schematic\Attributes\Instantiation;

use Neos\Schematic\Coercion\InvalidValue;
use ReflectionClass;

/**
 * Builds an instance by bypassing constructor visibility (so private constructors are supported) and then invoking
 * the constructor with the given positional arguments. A constructor that rejects its input by throwing
 * \InvalidArgumentException (e.g. via webmozart/assert) is adapted into the {@see InvalidValue} marker.
 *
 * @internal
 */
final class Construction
{
    /**
     * @param ReflectionClass<object> $reflectionClass
     * @param list<mixed> $arguments
     * @throws InvalidValue
     */
    public static function construct(ReflectionClass $reflectionClass, array $arguments): object
    {
        $instance = $reflectionClass->newInstanceWithoutConstructor();
        $constructor = $reflectionClass->getConstructor();
        if ($constructor !== null) {
            try {
                $constructor->invokeArgs($instance, $arguments);
            } catch (\InvalidArgumentException $exception) {
                throw new InvalidValue($exception->getMessage(), [], $exception);
            }
        }
        return $instance;
    }
}
