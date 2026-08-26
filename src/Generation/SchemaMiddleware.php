<?php

declare(strict_types=1);

namespace Neos\Schematic\Generation;

use Neos\Schematic\Schema\Schema;

/**
 * An onion layer of schema generation. A middleware can short-circuit (describe a class it recognises) or call
 * `$next` and augment the result. Caching is itself just a SchemaMiddleware.
 *
 *
 * TODO Rename to GeneratorMiddleware (or similar to make distinct)
 */
interface SchemaMiddleware
{
    /**
     * @param class-string $className
     * @param SchemaGenerator $next continuation of the chain for the *same* class
     * @param SchemaGenerator $root the (cache-fronted) root pipeline, for resolving *nested* class types
     * @throws UnsupportedTypeException
     */
    public function process(string $className, SchemaGenerator $next, SchemaGenerator $root): Schema;
}
