<?php

declare(strict_types=1);

namespace Neos\Schematic\Attributes\Serialization;

use Neos\Schematic\Serialization\UnextractableValue;

/**
 * Reads a declared property off an instance, bypassing visibility — the mirror of
 * {@see \Neos\Schematic\Attributes\Instantiation\Construction}, which bypasses constructor visibility so value
 * objects can keep private constructors. See ADR 0009.
 *
 * @internal
 */
final class PropertyAccess
{
    /**
     * @throws UnextractableValue if the property is not declared on the instance, or is uninitialized
     */
    public static function read(object $instance, string $propertyName, string $extractor): mixed
    {
        if (!property_exists($instance, $propertyName)) {
            throw new UnextractableValue(sprintf(
                '%s expected "%s" to expose a property "$%s" (matching the schema), but it does not declare one',
                $extractor,
                $instance::class,
                $propertyName,
            ), 1751885301);
        }
        $reflectionProperty = new \ReflectionProperty($instance, $propertyName);
        if (!$reflectionProperty->isInitialized($instance)) {
            throw new UnextractableValue(sprintf(
                '%s cannot read "%s::$%s": the property is uninitialized',
                $extractor,
                $instance::class,
                $propertyName,
            ), 1751885302);
        }
        return $reflectionProperty->getValue($instance);
    }
}
