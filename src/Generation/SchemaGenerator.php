<?php

declare(strict_types=1);

namespace Neos\Schematic\Generation;

use Neos\Schematic\Schema\Schema;

/**
 * Produces a {@see Schema} for a class. The core ships no implementation that reflects — every Schema is produced
 * by a {@see SchemaMiddleware}; see {@see SchemaFactory}.
 */
interface SchemaGenerator
{
    /**
     * @param class-string $className
     * @throws UnsupportedTypeException if no middleware produced a schema for the class
     */
    public function generate(string $className): Schema;
}
