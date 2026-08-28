<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Discovery\Fixtures;

/**
 * Half of a cycle that only closes one class later.
 */
final readonly class MutuallyRecursiveA
{
    public function __construct(public MutuallyRecursiveB $b) {}
}
