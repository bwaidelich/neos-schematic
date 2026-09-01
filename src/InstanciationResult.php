<?php

declare(strict_types=1);

namespace Neos\Schematic;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Validation\Issues;

/**
 * The outcome of {@see Schematic::instanciate()}: *either* the instance that was built, *or* every reason why the
 * payload could not be built. The {@see self::$issues} come from `neos/jsonschema`, so a caller can report them with
 * their paths as validation found them.
 *
 * Two ways to read it, for the two kinds of caller. Input from outside inspects {@see self::$success} and reports
 * the issues; a named constructor, where there is no half-built value object to hand back, takes
 * {@see self::valueOrThrow()} and is done with it.
 *
 * @template T of ProvidesSchema
 */
final readonly class InstanciationResult
{
    /**
     * @param T|null $value
     * @param class-string<T> $className
     */
    private function __construct(
        public bool $success,
        private object|null $value,
        private string $className,
        public Issues $issues,
    ) {}

    /**
     * @template V of ProvidesSchema
     * @param V $value
     * @return self<V>
     */
    public static function built(object $value): self
    {
        return new self(true, $value, $value::class, new Issues());
    }

    /**
     * @template V of ProvidesSchema
     * @param class-string<V> $className
     * @return self<V>
     */
    public static function failed(string $className, Issues $issues): self
    {
        if ($issues->toArray() === []) {
            throw new \InvalidArgumentException('A failed InstanciationResult requires at least one issue', 1783600500);
        }
        return new self(false, null, $className, $issues);
    }

    /**
     * @return T
     * @throws \LogicException if the instanciation failed — inspect {@see self::$success} first
     */
    public function value(): object
    {
        if (!$this->success || $this->value === null) {
            throw new \LogicException('Cannot read the value of a failed instanciation', 1783600501);
        }
        return $this->value;
    }

    /**
     * The same value, for the caller that has no use for a failed outcome: a named constructor enforcing its own
     * invariant, where the input is the caller's own and a bad one is a bug rather than a report to render.
     *
     * @return T
     * @throws CoercionFailed if the instanciation failed
     */
    public function valueOrThrow(): object
    {
        if (!$this->success || $this->value === null) {
            throw CoercionFailed::forClass($this->className, $this->issues);
        }
        return $this->value;
    }
}
