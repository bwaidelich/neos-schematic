<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Discovery\Fixtures;

enum Status: string
{
    case draft = 'draft';
    case published = 'published';
}
