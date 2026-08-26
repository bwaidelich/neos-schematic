<?php

declare(strict_types=1);

namespace Neos\Schematic\Schema;

/**
 * The structural role a {@see Schema} node plays. The discriminator the Coercer / Instantiator dispatch on, and the
 * thing a {@see Schema} named constructor stamps — so the wrapped JSON Schema, child shape and behaviour stay in sync.
 */
enum Kind
{
    case Scalar;
    case Object;
    case List;
    /**
     * A *discriminated* union: an interface (or abstract class) plus the candidate schemas its implementations
     * produce, keyed by the value of a discriminator property. Unlike a bare `oneOf` this is determinate — the
     * discriminator says which branch applies — which is exactly what lets the Coercer resolve it.
     */
    case Union;
}
