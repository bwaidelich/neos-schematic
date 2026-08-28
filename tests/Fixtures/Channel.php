<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

enum Channel implements ProvidesSchema
{
    case email;
    case phone;

    public static function schema(): Schema
    {
        return AutoDiscoveringSchema::analyze(self::class);
    }
}
