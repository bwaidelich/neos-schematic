<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

use Neos\Schematic\Attributes\Discriminator;

/**
 * The mapping names a class that does not implement this interface.
 */
#[Discriminator(propertyName: 'type', mapping: ['wrong' => Contact::class])]
interface MisMapped {}
