<?php

declare(strict_types=1);

namespace Neos\Schematic\Instantiation;

use Neos\JsonSchema\ProvidesSchema;
use Neos\Schematic\Instantiation\Helper\Construct;
use Neos\Schematic\Instantiation\Helper\MemberType;
use Neos\Schematic\Reflection\ClassShape;
use Neos\Schematic\Reflection\Nature;

/**
 * A class that is a collection of one thing — `Tags{Tag ...$tags}` — built by descending into each item and
 * spreading them into the constructor.
 *
 * @api
 */
final readonly class ListOfItems implements Instantiator
{
    public function __construct(private Instantiator $next) {}

    /**
     * @template T of ProvidesSchema
     * @param class-string<T> $className
     * @return T
     */
    public function build(string $className, mixed $value, Instantiator $root): object
    {
        if (Nature::fromSchema($className::schema()) !== Nature::ListOf) {
            return $this->next->build($className, $value, $root);
        }
        if (!is_array($value)) {
            throw new \LogicException(sprintf('Expected a list to build "%s", got %s', $className, get_debug_type($value)), 1783600403);
        }
        $itemType = MemberType::of(ClassShape::of($className)->parameters[0] ?? null);
        $items = [];
        foreach ($value as $item) {
            $items[] = $itemType === null ? $item : $root->build($itemType, $item, $root);
        }
        return Construct::invoke($className, $items);
    }
}
