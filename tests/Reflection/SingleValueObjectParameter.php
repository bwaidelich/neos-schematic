<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Reflection;

use Neos\Schematic\Tests\Discovery\Fixtures\Title;

/**
 * One parameter, but a class-typed one: it arrives as an object, not as a primitive, so it is a shape with a
 * single property rather than a scalar.
 */
final readonly class SingleValueObjectParameter
{
    public function __construct(public Title $title) {}
}
