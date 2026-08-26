<?php

declare(strict_types=1);

namespace Neos\Schematic\Attributes;

use Attribute;

/**
 * Excludes a constructor parameter from the generated shape schema. The parameter must be optional (have a default),
 * since it will not be provided during coercion.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Ignore {}
