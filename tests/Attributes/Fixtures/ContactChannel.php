<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

/**
 * A *pure* enum — described by its case names, since it has no backing values.
 */
enum ContactChannel
{
    case email;
    case phone;
}
