<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

/**
 * An ordinary class that never opted into being described. Asking for its schema is an error, not an invitation
 * to derive one.
 */
final readonly class WithoutSchema
{
    public function __construct(public string $value) {}
}
