<?php

declare(strict_types=1);

namespace Neos\Schematic;

use Neos\JsonSchema\ProvidesSchema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * Thrown when a class is asked for its schema and does not own one.
 *
 * Owning a schema is **opt-in, always**: a class says so by implementing {@see ProvidesSchema}. Deriving one from
 * whatever the class happens to look like would make the published contract an accident of the constructor —
 * every parameter rename a silent change to it — and would quietly describe classes that were never meant to
 * cross a wire at all.
 *
 * The remedy is one method. A class with nothing special to say can still fill it in mechanically:
 *
 *     public static function schema(): Schema
 *     {
 *         static $schema = null;
 *         if (!$schema instanceof Schema) {
 *             $schema = AutoDiscoveringSchema::analyze(self::class);
 *         }
 *         return $schema;
 *     }
 *
 * — which is the same derivation, with the difference that the class asked for it. {@see AutoDiscoveringSchema}
 * recurses into nested types on its own from there, so only the types a consumer names need the method.
 */
final class SchemaNotProvided extends \RuntimeException
{
    /**
     * @param class-string $className
     */
    public static function forClass(string $className): self
    {
        return new self(sprintf(
            '"%s" provides no schema: implement %s (its schema() may return AutoDiscoveringSchema::analyze(self::class))',
            $className,
            ProvidesSchema::class,
        ), 1783600601);
    }
}
