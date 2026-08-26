<?php

declare(strict_types=1);

namespace Neos\Schematic\Attributes;

use Attribute;

/**
 * Marks an interface (or abstract class) as a polymorphic type, naming the property that says which implementation
 * a payload denotes and enumerating the accepted values.
 *
 * The `mapping` is **required**, deliberately: the equivalent attribute in `wwwision/types` allows it to be
 * omitted, and then treats the discriminator value from the input as a class name to instantiate. That is
 * attacker-controlled class instantiation, and we do not reproduce it — every accepted value is enumerated here,
 * so no class-string ever reaches construction from input. See ADR 0010.
 *
 * Without this attribute an interface or abstract class is not described at all: the middleware passes it on, and
 * an unhandled one ends in an `UnsupportedTypeException`. Enumerating the implementations is the point — nothing
 * scans the classmap to find them.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Discriminator
{
    /**
     * @param non-empty-string $propertyName the property carrying the type tag, e.g. `type`
     * @param array<non-empty-string, class-string> $mapping accepted tag value => the implementation it denotes
     */
    public function __construct(
        public readonly string $propertyName,
        public readonly array $mapping,
    ) {
        if ($mapping === []) {
            throw new \InvalidArgumentException(sprintf('The discriminator mapping for "%s" must not be empty', $propertyName), 1751885510);
        }
    }
}
