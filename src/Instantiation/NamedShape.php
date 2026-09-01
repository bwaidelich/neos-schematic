<?php

declare(strict_types=1);

namespace Neos\Schematic\Instantiation;

use Neos\JsonSchema\ProvidesSchema;
use Neos\Schematic\Instantiation\Helper\Construct;
use Neos\Schematic\Instantiation\Helper\MemberType;
use Neos\Schematic\Reflection\ClassShape;
use Neos\Schematic\Reflection\Nature;

/**
 * Anything with named members, built by passing them as *named* arguments so that declaration order stops
 * mattering and a member with a default may simply be absent.
 *
 * Normally, is the last of the built-in links: it is what a class is when nothing more specific was said about it.
 *
 * @api
 */
final readonly class NamedShape implements Instantiator
{
    public function __construct(private Instantiator $next) {}

    /**
     * @template T of ProvidesSchema
     * @param class-string<T> $className
     * @return T
     */
    public function build(string $className, mixed $value, Instantiator $root): object
    {
        if (Nature::fromSchema($className::schema()) !== Nature::Shape) {
            return $this->next->build($className, $value, $root);
        }
        if (!is_array($value)) {
            throw new \LogicException(sprintf('Expected an array to build "%s", got %s', $className, get_debug_type($value)), 1783600404);
        }
        $arguments = [];
        foreach (ClassShape::of($className)->parameters as $parameter) {
            $name = $parameter->getName();
            if (!array_key_exists($name, $value)) {
                if ($parameter->isDefaultValueAvailable()) {
                    continue;
                }
                // the schema declares a parameter without a default as required, so validation already rejected
                // an input missing it
                throw new \LogicException(sprintf('Missing value for required parameter "$%s" of "%s"', $name, $className), 1783600405);
            }
            $arguments[$name] = self::member($parameter, $value[$name], $root);
        }
        return Construct::invoke($className, $arguments);
    }

    /**
     * What one member is given: the primitive it arrived as, or the instance built from it — from the top of the
     * chain, because a member is a value of its own kind.
     */
    private static function member(\ReflectionParameter $parameter, mixed $value, Instantiator $root): mixed
    {
        // a null needs no type to build it, so it is answered before the parameter's own type is asked for
        if ($value === null) {
            return $value;
        }
        $className = MemberType::of($parameter);
        return $className === null ? $value : $root->build($className, $value, $root);
    }
}
