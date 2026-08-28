<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Discovery\Fixtures;

/**
 * A type holding itself: the schema graph would have to contain itself, so analysis stops.
 */
final readonly class SelfReferencing
{
    public function __construct(public SelfReferencing|null $next) {}
}
