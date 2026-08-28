<?php

declare(strict_types=1);

namespace Neos\Schematic\Discovery;

/**
 * Thrown when a class describes no schema that can be derived from it — an enum with no cases, a genuine union or
 * intersection type, `mixed`, or a builtin that is not one of the four JSON scalars.
 *
 * It is always a *generation*-time failure, i.e. a programming error, never a reaction to input.
 */
final class UnsupportedTypeException extends \RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message, 1783600301);
    }
}
