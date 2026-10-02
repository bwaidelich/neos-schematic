<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Discovery\Fixtures;

use Neos\Schematic\Discovery\Description;

/**
 * A shape described on the class and on most of its members: a member's own type (Slug, DescribedStatus) is
 * described or not, and the parameter's description, where there is one, is the one that wins.
 */
#[Description('A blog post')]
final readonly class DescribedPost
{
    public function __construct(
        #[Description('The address of the post')]
        public Slug $slug,
        #[Description('Who wrote it')]
        public string $author,
        public DescribedStatus $status,
        public string $body,
        #[Description('Shown below the title')]
        public string|null $subtitle = null,
    ) {}
}
