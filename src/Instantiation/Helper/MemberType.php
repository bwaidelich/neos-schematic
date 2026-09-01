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
     * The class a member's value is built as, or `null` when nothing describes it and the primitive stands for
     * itself — a builtin, or a union none of whose class branches accepts the value.
     *
     * A union has no single type to hand on, so the value decides: the branches are tried in the order they are
     * declared, which is the order the published `anyOf` lists them, and the first one whose schema accepts the
     * primitives is the branch it gets built as.
     *
     * Reflection is the one place a class name arrives with nothing to vouch for it, so this is where owning a
     * schema is established rather than assumed — everything downstream takes a `class-string<ProvidesSchema>`
     * and has nothing left to ask.
     *
     * @return class-string<ProvidesSchema>|null
     */
    public static function of(\ReflectionParameter|null $parameter, mixed $value): string|null
    {
        $type = $parameter?->getType();
        if ($type instanceof \ReflectionUnionType) {
            return self::branch($type, $parameter, $value);
        }
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }
        return self::described($type->getName(), $parameter);
    }

    /**
     * @return class-string<ProvidesSchema>|null
     */
    private static function branch(\ReflectionUnionType $type, \ReflectionParameter $parameter, mixed $value): string|null
    {
        foreach ($type->getTypes() as $branch) {
            if (!$branch instanceof \ReflectionNamedType || $branch->isBuiltin()) {
                // a builtin branch describes no shape to build, so it is the primitive standing for itself
                continue;
            }
            $className = self::described($branch->getName(), $parameter);
            if ($className::schema()->validate($value)->valid) {
                return $className;
            }
        }
        return null;
    }

    /**
     * @return class-string<ProvidesSchema>
     */
    private static function described(string $className, \ReflectionParameter $parameter): string
    {
        if (!is_a($className, ProvidesSchema::class, true)) {
            throw new \LogicException(sprintf(
                'Cannot build the member "$%s" typed "%s": it provides no schema, so there is nothing to build it '
                . 'against — implement %s',
                $parameter->getName(),
                $className,
                ProvidesSchema::class,
            ), 1783600413);
        }
        return $className;
    }
}
