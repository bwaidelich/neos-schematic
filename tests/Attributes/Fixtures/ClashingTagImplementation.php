<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

/**
 * Declares a real property called `type`, which would fight with the discriminator over the same key.
 */
final readonly class ClashingTagImplementation implements ClashingTag
{
    private function __construct(
        public ContactName $type,
    ) {}
}
