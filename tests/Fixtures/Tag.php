<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

final readonly class Tag implements ProvidesSchema
{
    public function __construct(public string $value) {}

    public static function schema(): Schema
    {
        return AutoDiscoveringSchema::analyze(self::class);
    }
}
