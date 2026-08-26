<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

use Neos\Schematic\Attributes\Description;

#[Description('Whether a contact is still reachable')]
enum ContactStatus: string
{
    case active = 'active';
    case archived = 'archived';
}
