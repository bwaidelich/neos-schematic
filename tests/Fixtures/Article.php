<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

/**
 * A plain record: a public constructor and no schema of its own, so its schema is derived from that constructor —
 * with `Slug` contributing its own.
 */
final readonly class Article
{
    public function __construct(
        public Slug $slug,
        public string $title,
        public string|null $subtitle = null,
    ) {}
}
