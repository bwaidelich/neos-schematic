<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

/**
 * An interface without a #[Discriminator]: still not describable, since nothing enumerates its implementations.
 */
interface UndiscriminatedShape {}
