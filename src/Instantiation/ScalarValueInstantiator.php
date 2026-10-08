<?php

declare(strict_types=1);

namespace Neos\Schematic\Instantiation;

use Neos\Schematic\Instantiation\Helper\Construct;
use Neos\Schematic\Reflection\ClassShape;
use Neos\Schematic\Reflection\Nature;
use Neos\Schematic\Schematic;

/**
 * A class that *is* one value — `Slug{string $value}` — built by handing the primitive to its constructor.
 *
 * @api
 */
final readonly class ScalarValueInstantiator implements Instantiator
{
    public function __construct(private Instantiator $next) {}

    /**
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    public function build(string $className, mixed $value, Instantiator $root): object
    {
        if (Nature::fromSchema(Schematic::schemaOf($className)) !== Nature::Scalar) {
            return $this->next->build($className, $value, $root);
        }
        if (ClassShape::of($className)->parameters === []) {
            throw new \LogicException(sprintf('Expected "%s" to have a constructor parameter to hold a single value', $className), 1783600411);
        }
        return Construct::invoke($className, [$value]);
    }
}
