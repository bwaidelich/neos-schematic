<?php

declare(strict_types=1);

namespace Neos\Schematic\Generation;

use Neos\Schematic\Schema\Schema;

/**
 * The public, injectable entry point for obtaining a {@see Schema} for a class.
 *
 * Wired once with an ordered list of {@see SchemaMiddleware} (a caching middleware is typically the first). Nested class
 * types are resolved by re-entering this same pipeline, so they pass through caching too. The engine ships no
 * middleware that reflects — register one (e.g. the `ReflectionMiddleware` from the opt-in attributes namespace)
 * to produce schemas; an unhandled class results in an {@see UnsupportedTypeException}.
 *
 * Because it is the re-entry point, this is also where recursive types are caught: a class that re-enters while
 * already being generated raises a {@see CircularReferenceException} instead of exhausting the stack.
 */
final class SchemaFactory implements SchemaGenerator
{
    /**
     * @var list<SchemaMiddleware>
     */
    private readonly array $middlewares;

    /**
     * The classes currently being generated, in order — the guard against recursive types.
     *
     * Transient state, scoped to a single `generate()` call tree and always cleared on the way out. This is the
     * only place that sees the whole tree, because nested types re-enter *here*.
     *
     * @var array<class-string, true>
     */
    private array $inProgress = [];

    public function __construct(SchemaMiddleware ...$middlewares)
    {
        $this->middlewares = array_values($middlewares);
    }

    /**
     * @param class-string $className
     */
    public function for(string $className): Schema
    {
        return $this->generate($className);
    }

    public function generate(string $className): Schema
    {
        if (isset($this->inProgress[$className])) {
            throw CircularReferenceException::forPath([...array_keys($this->inProgress), $className]);
        }
        $this->inProgress[$className] = true;
        try {
            return $this->chain(0)->generate($className);
        } finally {
            // `finally` rather than a plain unset: a failed generation must not leave the class marked, or the
            // next attempt would be misreported as a cycle
            unset($this->inProgress[$className]);
        }
    }

    private function chain(int $index): SchemaGenerator
    {
        if (!isset($this->middlewares[$index])) {
            return new TerminalGenerator();
        }
        return new ChainLink($this->middlewares[$index], $this->chain($index + 1), $this);
    }
}
