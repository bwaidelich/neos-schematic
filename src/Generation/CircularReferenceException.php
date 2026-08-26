<?php

declare(strict_types=1);

namespace Neos\Schematic\Generation;

/**
 * Thrown when generation re-enters a class it is already generating — a recursive type (`Node` holding a `Node`,
 * or a cycle through several classes).
 *
 * A {@see \Neos\Schematic\Schema\Schema} graph holds its children *directly*, so it cannot represent a cycle; and
 * because the caching middleware only writes an entry once generation has *finished*, the recursive call never
 * finds one to stop at. Without this guard the pipeline recurses until the stack is exhausted, which is a crash
 * rather than a diagnosis. Supporting recursive types properly would mean a reference kind in `Schema` — see
 * TODO.md.
 */
final class CircularReferenceException extends \RuntimeException
{
    /**
     * @param list<class-string> $path the generation stack, from the outermost class to the repeated one
     */
    public static function forPath(array $path): self
    {
        return new self(sprintf(
            'Circular reference while generating a schema: %s. Recursive types are not supported.',
            implode(' -> ', $path),
        ), 1751885400);
    }
}
