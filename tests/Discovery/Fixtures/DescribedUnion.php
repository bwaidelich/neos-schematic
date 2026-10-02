<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Discovery\Fixtures;

use Neos\Schematic\Discovery\Description;

/**
 * A description on a genuine union has nowhere to go, since an `anyOf` carries none of its own.
 */
final readonly class DescribedUnion
{
    public function __construct(
        #[Description('Either one')]
        public string|int $either,
    ) {}
}
