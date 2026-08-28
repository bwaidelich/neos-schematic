<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Discovery\Fixtures;

/**
 * `array` is a builtin that is not a scalar: nothing says what is in it, so it cannot be described.
 */
final readonly class Untyped
{
    /**
     * @param array<mixed> $anything
     */
    public function __construct(public array $anything) {}
}
