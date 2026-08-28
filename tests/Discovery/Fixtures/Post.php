<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Discovery\Fixtures;

/**
 * A shape over every builtin scalar plus a value object.
 */
final readonly class Post
{
    public function __construct(
        public Slug $slug,
        public string $author,
        public int $views,
        public bool $draft,
        public float $rating,
    ) {}
}
