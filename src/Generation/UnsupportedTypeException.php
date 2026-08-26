<?php

declare(strict_types=1);

namespace Neos\Schematic\Generation;

/**
 * Thrown when no {@see SchemaMiddleware} in the pipeline produced a {@see \Neos\Schematic\Schema\Schema} for a class.
 */
final class UnsupportedTypeException extends \RuntimeException
{
    /**
     * @param class-string $className
     */
    public static function forClass(string $className): self
    {
        return new self(sprintf('No middleware produced a schema for "%s"', $className), 1751885000);
    }
}
