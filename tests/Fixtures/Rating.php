<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

enum Rating: int implements ProvidesSchema
{
    case low = 1;
    case high = 5;

    public static function schema(): Schema
    {
        return AutoDiscoveringSchema::analyze(self::class);
    }
}
