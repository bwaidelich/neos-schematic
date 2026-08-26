<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

/**
 * The most natural recursive shape, and now the most reachable one: nullability makes `Node|null $parent` a
 * perfectly ordinary thing to write.
 */
final readonly class SelfReferencingNode
{
    private function __construct(
        public ContactName $label,
        public SelfReferencingNode|null $parent = null,
    ) {}
}
