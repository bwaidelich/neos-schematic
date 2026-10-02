<?php

declare(strict_types=1);

namespace Neos\Schematic\Discovery;

/**
 * Thrown when a class describes no schema that can be derived from it — an enum with no cases, an intersection type,
 * `mixed`, a builtin that is not one of the four JSON scalars, or a {@see Description} on a schema that has no
 * `description` of its own (a genuine union).
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
