<?php

declare(strict_types=1);

namespace Neos\Schematic\Discovery;

use Neos\JsonSchema\ProvidesSchema;

/**
 * Thrown when a class describes no schema that can be derived from it — an enum with no cases, a genuine union or
 * intersection type, `mixed`, a builtin that is not one of the four JSON scalars, or a class that provides no schema
 * and keeps its constructor private.
 *
 * It is always a *generation*-time failure, i.e. a programming error, never a reaction to input.
 */
final class UnsupportedTypeException extends \RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message, 1783600301);
    }

    /**
     * A class owning no schema whose constructor is not public: describing it from that constructor would bypass
     * whatever its own way in enforces.
     *
     * @param class-string $className
     */
    public static function notDescribable(string $className): self
    {
        return new self(sprintf(
            '"%s" provides no schema and its constructor is not public, so describing it from that constructor would '
            . 'bypass whatever its own way in enforces — implement %s',
            $className,
            ProvidesSchema::class,
        ), 1783600303);
    }
}
