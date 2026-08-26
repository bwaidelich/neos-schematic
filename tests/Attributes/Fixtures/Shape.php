<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

use Neos\Schematic\Attributes\Description;
use Neos\Schematic\Attributes\Discriminator;

#[Description('A drawable shape')]
#[Discriminator(propertyName: 'type', mapping: ['circle' => Circle::class, 'rectangle' => Rectangle::class])]
interface Shape {}
