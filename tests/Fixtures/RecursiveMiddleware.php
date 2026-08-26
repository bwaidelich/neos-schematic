<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\JsonSchema\ObjectSchema as JsonObjectSchema;
use Neos\Schematic\Generation\SchemaGenerator;
use Neos\Schematic\Generation\SchemaMiddleware;
use Neos\Schematic\Schema\Schema;

/**
 * A middleware that re-enters the root pipeline for a configured "child" class, so recursion can be exercised
 * without the reflecting middleware from the attributes namespace (which the engine's tests must not name).
 */
final class RecursiveMiddleware implements SchemaMiddleware
{
    /**
     * @param array<class-string, class-string> $edges maps a class to the class its schema descends into
     */
    public function __construct(
        private readonly array $edges,
    ) {}

    public function process(string $className, SchemaGenerator $next, SchemaGenerator $root): Schema
    {
        if (!isset($this->edges[$className])) {
            return $next->generate($className);
        }
        return Schema::object(
            JsonObjectSchema::create(),
            ['child' => $root->generate($this->edges[$className])],
            $className,
        );
    }
}
