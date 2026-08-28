<?php

declare(strict_types=1);

namespace Neos\Schematic\Reflection;

/**
 * What kind of thing a class is, as far as building it from primitives (and reading it back) is concerned.
 *
 * Decided from the class alone by {@see ClassShape}, and unambiguous by construction — see the rules there.
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
}
