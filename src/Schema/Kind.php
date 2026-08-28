<?php

declare(strict_types=1);

namespace Neos\Schematic\Schema;

/**
 * The structural role a {@see Schema} node plays. The tag the Coercer / Instantiator dispatch on, and the thing a
 * {@see Schema} named constructor stamps — so the wrapped JSON Schema, child shape and behaviour stay in sync.
 */
enum Kind
{
    case Scalar;
    case Object;
    case List;
}
