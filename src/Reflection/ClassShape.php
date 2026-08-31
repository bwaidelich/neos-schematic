<?php

declare(strict_types=1);

namespace Neos\Schematic\Reflection;

/**
 * A reading of a class taken from the class alone: its {@see Nature}, and the constructor parameters that make it
 * up. Read once and passed around.
 *
 * **Where this is the answer, and where it is a fallback.** It is the whole answer for
 * {@see \Neos\Schematic\Discovery\AutoDiscoveringSchema}, which derives a schema *from* a class and so has none
 * to consult, and for {@see \Neos\Schematic\Serialization\Serializer}, which compares this reading against the
 * published one. {@see \Neos\Schematic\Serialization\Serializer} prefers the schema and falls back to this
 * where the schema has no single structural reading — a multi-branch union, an `allOf`, a `$ref`, or nothing.
 * {@see \Neos\Schematic\Instantiation\Instantiator} still reads by nature alone.
 *
 * The nature is decided from the class alone, in this order, which makes it unambiguous:
 *
 * | Class                                       | Nature   |
 * | ------------------------------------------- | -------- |
 * | an `enum`                                    | Enum     |
 * | one variadic parameter                       | ListOf   |
 * | one parameter typed `string\|int\|float\|bool` | Scalar   |
 * | anything else                                | Shape    |
 *
 * Variadic is checked before scalar, so `Parts{string ...$parts}` is a collection rather than one value; and a
 * class-typed single parameter stays a Shape, because only a builtin scalar arrives as a bare primitive. A value
 * object wanting collection semantics without variadics says so by making the parameter variadic, and a
 * one-member envelope whose member is a scalar is the one case a constructor cannot settle — `Success{bool $success}`
 * is written exactly like `Slug{string $value}` — so the schema settles that one, in
 * {@see \Neos\Schematic\Serialization\Serializer}.
 */
final readonly class ClassShape
{
    /**
     * @param class-string $className
     * @param list<\ReflectionParameter> $parameters
     */
    private function __construct(
        public string $className,
        public Nature $nature,
        public array $parameters,
    ) {}

    /**
     * @param class-string $className
     */
    public static function of(string $className): self
    {
        if (!class_exists($className) && !enum_exists($className) && !interface_exists($className)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a class, enum or interface', $className), 1783600201);
        }
        $reflectionClass = new \ReflectionClass($className);
        if ($reflectionClass->isEnum()) {
            return new self($className, Nature::Enum, []);
        }
        if ($reflectionClass->isInterface() || $reflectionClass->isAbstract()) {
            throw new \InvalidArgumentException(sprintf('"%s" cannot be instantiated, so it has no shape of its own', $className), 1783600202);
        }
        $parameters = $reflectionClass->getConstructor()?->getParameters() ?? [];
        return new self($className, self::natureOf($parameters), $parameters);
    }

    public function parameter(string $name): \ReflectionParameter|null
    {
        foreach ($this->parameters as $parameter) {
            if ($parameter->getName() === $name) {
                return $parameter;
            }
        }
        return null;
    }

    /**
     * @param list<\ReflectionParameter> $parameters
     */
    private static function natureOf(array $parameters): Nature
    {
        if (count($parameters) !== 1) {
            return Nature::Shape;
        }
        if ($parameters[0]->isVariadic()) {
            return Nature::ListOf;
        }
        $type = $parameters[0]->getType();
        if (!$type instanceof \ReflectionNamedType || !$type->isBuiltin()) {
            return Nature::Shape;
        }
        return in_array($type->getName(), ['string', 'int', 'float', 'bool'], true) ? Nature::Scalar : Nature::Shape;
    }
}
