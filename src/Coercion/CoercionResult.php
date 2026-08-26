<?php

declare(strict_types=1);

namespace Neos\Schematic\Coercion;

use Neos\JsonSchema\Validation\Issues;

/**
 * The outcome of coercing (and optionally instantiating) input: *either* the normalized value / built instance,
 * *or* the aggregated {@see Issues}. Returned instead of throwing, since invalid input is expected, not exceptional.
 */
final readonly class CoercionResult
{
    private function __construct(
        public bool $success,
        private mixed $value,
        public Issues $issues,
    ) {}

    public static function success(mixed $value): self
    {
        return new self(true, $value, new Issues());
    }

    public static function failure(Issues $issues): self
    {
        if ($issues->isEmpty()) {
            throw new \InvalidArgumentException('A failure result requires at least one issue', 1751884800);
        }
        return new self(false, null, $issues);
    }

    /**
     * The normalized value (phase 1) or built instance (phase 2).
     *
     * @throws \RuntimeException if this result is a failure
     */
    public function value(): mixed
    {
        if (!$this->success) {
            throw new \RuntimeException('Cannot retrieve the value of a failed CoercionResult', 1751884801);
        }
        return $this->value;
    }
}
