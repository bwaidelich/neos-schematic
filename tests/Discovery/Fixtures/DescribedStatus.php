<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Discovery\Fixtures;

use Neos\Schematic\Discovery\Description;

#[Description('Whether the post is visible')]
enum DescribedStatus: string
{
    case draft = 'draft';
    case published = 'published';
}
