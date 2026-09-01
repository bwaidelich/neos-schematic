<?php

declare(strict_types=1);

namespace Neos\Schematic\Reflection;

use Neos\JsonSchema\AnyOfSchema;
use Neos\JsonSchema\ArraySchema;
use Neos\JsonSchema\BooleanSchema;
use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\Nullable;
use Neos\JsonSchema\NumberSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\OneOfSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;

/**
 * What kind of thing a value is, as far as building it from primitives (and reading it back) is concerned.
 *
 * There are two ways to find out:
 *
 * - {@see self::fromSchema()} — from the **schema**, which is authoritative because it is the published contract. It is
 *   also the only one that can answer for a one-member envelope whose member is a scalar: `Success{bool $success}`
 *   is written exactly like `Slug{string $value}` and means the opposite. Asked of the schema a class owns, this
 *   is the whole of what building consults.
 * - {@see ClassShape} — from the **class**, where there is no schema to consult:
 *   {@see \Neos\Schematic\Discovery\AutoDiscoveringSchema} derives one *from* a class, and
 *   {@see \Neos\Schematic\Serialization\Serializer} reads a value under a *declared* type that may abstain.
 *
 * @internal
 */
enum Nature
{
    /** A PHP enum: its cases *are* its values, so nothing is constructed. */
    case Enum;

    /** One builtin-scalar constructor parameter: the class is that single value. */
    case Scalar;

    /** One variadic constructor parameter: the class is a collection of them. */
    case ListOf;

    /** Anything else: named parameters, mapped from (and back to) an array by name. */
    case Shape;

    /**
     * What the schema says, or `null` where it makes no structural claim at all — which is what
     * {@see \Neos\Schematic\Instantiation\UnbuildableInstantiator} refuses.
     *
     * **Building asks this and nothing else**, of the schema the class itself owns: `Nature::fromSchema($className::schema())`.
     * There is no fallback to the class there — a class whose published contract claims nothing has told the
     * engine nothing, and a link that knows what it is builds it instead. Nor does this ever answer
     * {@see self::Enum}: an enum publishes the schema of its *cases*, so it reads `Scalar`, and
     * {@see \Neos\Schematic\Instantiation\EnumCaseInstantiator} asks the class directly and is asked first.
     *
     * A nullable member is unwrapped to what it wraps first ({@see Nullable::unwrap()}), since a member that may
     * be absent is still that member. A union of several substantive branches is answered when they *agree* —
     * `oneOf` of two object schemas is an object whichever branch a value turns out to be, and which one it is
     * only matters to {@see \Neos\Schematic\Serialization\Serializer}, which asks once the members are primitives
     * and an empty one has to go out as `{}` or `[]`.
     * Branches that disagree, an `allOf`, a `$ref`: no claim, and `null`.
     */
    public static function fromSchema(Schema|null $schema): self|null
    {
        $schema = Nullable::unwrap($schema);
        if ($schema instanceof AnyOfSchema || $schema instanceof OneOfSchema) {
            return self::agreedBetween($schema);
        }
        return match (true) {
            $schema instanceof ObjectSchema => self::Shape,
            $schema instanceof ArraySchema => self::ListOf,
            $schema instanceof StringSchema,
            $schema instanceof IntegerSchema,
            $schema instanceof NumberSchema,
            $schema instanceof BooleanSchema => self::Scalar,
            default => null,
        };
    }

    /**
     * The one kind every branch of a union is, or `null` when they are not all the same kind.
     */
    private static function agreedBetween(AnyOfSchema|OneOfSchema $schema): self|null
    {
        $agreed = null;
        foreach ($schema as $branch) {
            $branchNature = self::fromSchema($branch);
            if ($branchNature === null || ($agreed !== null && $agreed !== $branchNature)) {
                return null;
            }
            $agreed = $branchNature;
        }
        return $agreed;
    }
}
