<?php

declare(strict_types=1);

namespace Neos\Schematic;

use Neos\JsonSchema\Validation\Issues;

/**
 * The outcome of {@see Schematic::map()}: *either* the instance that was built, *or* every reason why the payload could not be built.
 * The {@see self::$issues} come from `neos/jsonschema`, so a caller can report them with their paths as validation found them.
 */
final readonly class MappingResult
{
    private function __construct(
        public bool $success,
        private object|null $value,
        public Issues $issues,
    ) {}

    public static function built(object $value): self
    {
        return new self(true, $value, new Issues());
    }

    public static function failed(Issues $issues): self
    {
        if ($issues->toArray() === []) {
            throw new \InvalidArgumentException('A failed MappingResult requires at least one issue', 1783600500);
        }
        return new self(false, null, $issues);
    }

    /**
     * @throws \LogicException if the mapping failed — inspect {@see self::$success} first
     */
    public function value(): object
    {
        if (!$this->success || $this->value === null) {
            throw new \LogicException('Cannot read the value of a failed mapping', 1783600501);
        }
        return $this->value;
    }
}
