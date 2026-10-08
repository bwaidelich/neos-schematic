<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Discovery\Fixtures;

/**
 * A shape naming, as a member, a class that can neither describe itself nor be described on its behalf.
 */
final readonly class HoldsSealed
{
    public function __construct(public Sealed $sealed) {}
}
