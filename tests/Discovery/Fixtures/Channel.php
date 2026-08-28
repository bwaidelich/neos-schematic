<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Discovery\Fixtures;

/**
 * A *pure* enum, described by its case names.
 */
enum Channel
{
    case email;
    case phone;
}
