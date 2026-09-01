<?php

declare(strict_types=1);

namespace Neos\Schematic\Instantiation\Helper;

use Neos\JsonSchema\ProvidesSchema;

/**
 * @internal
 */
final class MemberType
{
    private function __construct() {}

    /**
     * The class a parameter is typed by, or `null` when it is a builtin (`?Foo` counts as `Foo`).
     *
     * Reflection is the one place a class name arrives with nothing to vouch for it, so this is where owning a
     * schema is established rather than assumed — everything downstream takes a `class-string<ProvidesSchema>`
     * and has nothing left to ask.
     *
     * @return class-string<ProvidesSchema>|null
     */
    public static function of(\ReflectionParameter|null $parameter): string|null
    {
        $type = $parameter?->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }
        /** @var class-string $name */
        $name = $type->getName();
        if (!is_a($name, ProvidesSchema::class, true)) {
            throw new \LogicException(sprintf(
                'Cannot build the member typed "%s": it provides no schema, so there is nothing to build it '
                . 'against — implement %s',
                $name,
                ProvidesSchema::class,
            ), 1783600413);
        }
        return $name;
    }
}
