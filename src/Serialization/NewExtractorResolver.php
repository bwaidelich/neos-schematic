<?php

declare(strict_types=1);

namespace Neos\Schematic\Serialization;

/**
 * The default {@see ExtractorResolver}: instantiates the extractor via `new` (extractors must be stateless / have
 * no required constructor arguments). Use {@see ContainerExtractorResolver} for extractors with dependencies.
 */
final class NewExtractorResolver implements ExtractorResolver
{
    public function resolve(string $extractorClassName): ValueExtractor
    {
        if (!class_exists($extractorClassName)) {
            throw new \InvalidArgumentException(sprintf('ValueExtractor class "%s" does not exist', $extractorClassName), 1751884910);
        }
        // checked before instantiating: constructing an arbitrary class first would surface a misconfiguration as
        // whatever that constructor happens to throw (e.g. ArgumentCountError) instead of this message
        if (!is_a($extractorClassName, ValueExtractor::class, true)) {
            throw new \InvalidArgumentException(sprintf('Class "%s" is not a %s', $extractorClassName, ValueExtractor::class), 1751884911);
        }
        return new $extractorClassName();
    }
}
