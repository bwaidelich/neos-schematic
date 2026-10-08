<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

/**
 * A plain record holding another plain record, a collection and an enum — none of the records owning a schema.
 */
final readonly class Feature
{
    public function __construct(
        public Article $article,
        public Tags $tags,
        public Status $status,
    ) {}
}
