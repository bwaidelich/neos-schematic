<?php

declare(strict_types=1);

namespace Neos\Schematic\Coercion;

/**
 * Thrown when the {@see Coercer} encounters a JSON Schema type or keyword it cannot enforce.
 *
 * The Coercer is deliberately strict: rather than silently ignoring an unsupported constraint (and risking a
 * false "valid"), it fails loud. Adding support for the keyword in question resolves it.
 */
final class UnsupportedSchemaException extends \RuntimeException {}
