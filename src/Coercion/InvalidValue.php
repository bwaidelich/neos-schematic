<?php

declare(strict_types=1);

namespace Neos\Schematic\Coercion;

/**
 * The dedicated marker exception a {@see \Neos\Schematic\Instantiation\InstanceFactory} throws to signal an invariant
 * the schema cannot express (e.g. `start < end`).
 *
 * The {@see \Neos\Schematic\Instantiation\Instantiator} catches *only* this exception and turns it into an
 * {@see Issue}; any other exception propagates as a genuine bug.
 */
final class InvalidValue extends \RuntimeException
{
    /**
     * The phase-2 {@see \Neos\JsonSchema\Validation\Issue} code schematic owns (jsonschema's validation vocabulary
     * has no instantiation concept): the {@see \Neos\Schematic\Instantiation\Mapper} folds this exception into the
     * unified result under this code.
     */
    public const ISSUE_CODE = 'invalid_value';

    /**
     * @param list<string|int> $path
     */
    public function __construct(
        string $message,
        public readonly array $path = [],
        \Throwable|null $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
