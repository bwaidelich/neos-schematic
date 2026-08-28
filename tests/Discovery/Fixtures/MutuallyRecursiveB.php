<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Discovery\Fixtures;

final readonly class MutuallyRecursiveB
{
    public function __construct(public MutuallyRecursiveA $a) {}
}
