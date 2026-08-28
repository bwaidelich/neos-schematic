<?php

declare(strict_types=1);

namespace Neos\Schematic\Discovery;

/**
 * Thrown when analysis re-enters a class it is already analyzing — a recursive type (`Node` holding a `Node`, or a
 * cycle through several classes).
 *
 * An analyzed schema embeds its children *directly*, so it cannot represent a cycle; and a class memoizing its own
 * `schema()` in a `static` variable assigns it only *after* analysis returns, so the recursive call never finds
 * one to stop at. Without this guard analysis recurses until the stack is exhausted, which is a crash rather than
 * a diagnosis.
 *
 * (`neos/jsonschema` ships a `ReferenceSchema`, so genuinely recursive types become plausible later.)
 */
final class CircularReferenceException extends \RuntimeException
{
    /**
     * @param list<class-string> $path the analysis stack, from the outermost class to the repeated one
     */
    public static function forPath(array $path): self
    {
        return new self(sprintf(
            'Circular reference while analyzing a schema: %s. Recursive types are not supported.',
            implode(' -> ', $path),
        ), 1783600302);
    }
}
