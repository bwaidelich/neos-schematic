<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

use Neos\Schematic\Attributes\Discriminator;

#[Discriminator(propertyName: 'type', mapping: ['clashing' => ClashingTagImplementation::class])]
interface ClashingTag {}
