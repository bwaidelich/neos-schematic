<?php

declare(strict_types=1);

namespace Neos\Schematic\Discovery;

/**
 * A human-readable description that {@see AutoDiscoveringSchema} writes into the `description` of the schema it
 * derives — on a **class** (or enum) for the schema of that type, on a **constructor parameter** for the schema of
 * that member.
 *
 * It is an annotation, not a constraint: it changes what a reader of the published schema is told, never which
 * values are accepted. A description on a parameter is the more specific one, so it replaces whatever the member's
 * type describes itself as — the same `Slug` may be "the post's address" in one place and "the author's handle" in
 * another.
 *
 * It may target properties, too, only because PHP applies an attribute on a *promoted* constructor parameter to the
 * property as well, and whatever reflects that property – a framework building proxies, say – must be able to
 * instantiate it. Analysis reads the parameter, never the property.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Description
{
    public function __construct(
        public string $value,
    ) {}
}
