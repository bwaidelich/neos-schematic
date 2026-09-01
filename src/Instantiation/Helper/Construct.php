<?php

declare(strict_types=1);

namespace Neos\Schematic\Instantiation\Helper;

/**
 * Helper for instanciation, also invoking private constructors.
 *
 * @internal
 */
final class Construct
{
    private function __construct() {}

    /**
     * @template T of object
     * @param class-string<T> $className
     * @param array<array-key, mixed> $arguments
     * @return T
     */
    public static function invoke(string $className, array $arguments): object
    {
        $reflectionClass = new \ReflectionClass($className);
        $constructor = $reflectionClass->getConstructor();
        if ($constructor === null || $constructor->isPublic()) {
            return $reflectionClass->newInstanceArgs($arguments);
        }

        $instance = $reflectionClass->newInstanceWithoutConstructor();
        $constructor->invokeArgs($instance, $arguments);
        return $instance;
    }
}
