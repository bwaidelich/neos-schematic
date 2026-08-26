<?php

declare(strict_types=1);

namespace Neos\Schematic\Serialization;

/**
 * Resolves a {@see ValueExtractor} instance from the class-string named on a Schema node.
 */
interface ExtractorResolver
{
    /**
     * @param class-string $extractorClassName
     */
    public function resolve(string $extractorClassName): ValueExtractor;
}
