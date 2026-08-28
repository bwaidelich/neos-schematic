<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Discovery\Fixtures;

/**
 * Parameters with defaults are optional, so they stay out of `required`.
 */
final readonly class PostWithDefaults
{
    public function __construct(
        public string $author,
        public string|null $nickname = null,
        public int $views = 0,
    ) {}
}
