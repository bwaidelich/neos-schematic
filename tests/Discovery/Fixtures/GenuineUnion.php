<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Discovery\Fixtures;

/**
 * A genuine union — not the `?T` spelling — which reflection reports as a ReflectionUnionType.
 */
final readonly class GenuineUnion
{
    public function __construct(public string|int $either) {}
}
