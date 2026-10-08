<?php

declare(strict_types=1);

namespace Neos\Schematic\Instantiation\Helper;

use Neos\Schematic\Schematic;

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
     * Whether the class has a schema — its own, or one derived on its behalf — is {@see Schematic::schemaOf()}'s
     * to say, and it refuses there a class that has neither.
     *
     * @return class-string|null
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
        /** @var class-string $className */
        $className = $type->getName();
        return $className;
    }

    /**
     * @return class-string|null
     */
    private static function branch(\ReflectionUnionType $type, \ReflectionParameter $parameter, mixed $value): string|null
    {
        foreach ($type->getTypes() as $branch) {
            if (!$branch instanceof \ReflectionNamedType || $branch->isBuiltin()) {
                // a builtin branch describes no shape to build, so it is the primitive standing for itself
                continue;
            }
            /** @var class-string $className */
            $className = $branch->getName();
            if (Schematic::schemaOf($className)->validate($value)->valid) {
                return $className;
            }
        }
        return null;
    }
}
