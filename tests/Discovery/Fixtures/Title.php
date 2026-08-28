<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Discovery\Fixtures;

/**
 * A scalar value object with no schema of its own, so analysis derives one from its single parameter.
 */
final readonly class Title
{
    public function __construct(public string $value) {}
}
