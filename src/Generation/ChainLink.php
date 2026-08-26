<?php

declare(strict_types=1);

namespace Neos\Schematic\Generation;

use Neos\Schematic\Schema\Schema;

/**
 * Adapts a single {@see SchemaMiddleware} (bound to its `next` and `root` generators) to the {@see SchemaGenerator} interface,
 * so the pipeline can be composed as a chain.
 *
 * @internal
 */
final class ChainLink implements SchemaGenerator
{
    public function __construct(
        private readonly SchemaMiddleware $middleware,
        private readonly SchemaGenerator $next,
        private readonly SchemaGenerator $root,
    ) {}

    public function generate(string $className): Schema
    {
        return $this->middleware->process($className, $this->next, $this->root);
    }
}
