<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

enum ContactPriority: int
{
    case low = 1;
    case high = 10;
}
