<?php

declare(strict_types=1);

namespace Neos\Schematic;

use Neos\JsonSchema\Validation\Issues;

/**
 * Thrown by {@see Schematic::instantiate()} when the input does not satisfy the class's own schema.
 *
 * A named constructor is the one place a value object's invariant is enforced, so it *throws* — there is no
 * instance to hand back, and a caller writing `Slug::fromString($value)` wants that to be the end of it.
 *
 * {@see Schematic::map()} exists for the other case: a whole payload arriving from outside, where every issue in
 * it is wanted at once rather than the first one as an exception.
 */
final class CoercionFailed extends \RuntimeException
{
    private function __construct(
        string $message,
        public readonly Issues $issues,
    ) {
        parent::__construct($message, 1783600401);
    }

    /**
     * @param class-string $className
     */
    public static function forClass(string $className, Issues $issues): self
    {
        return new self(sprintf('Cannot build "%s": %s', $className, $issues), $issues);
    }
}
