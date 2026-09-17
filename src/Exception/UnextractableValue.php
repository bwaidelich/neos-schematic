<?php

declare(strict_types=1);

namespace Neos\Schematic\Exception;

/**
 * Thrown when an instance does not expose the state its class shape describes — a constructor parameter with no
 * public property of the same name, or a collection that is neither Traversable nor exposes its items.
 *
 * Never caused by *input*: it means a class and the way it is read back disagree, which is a programming error.
 * A class keeping its state private answers this by writing a `jsonSerialize()` of its own.
 */
final class UnextractableValue extends \RuntimeException {}
