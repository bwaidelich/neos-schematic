<?php

declare(strict_types=1);

namespace Neos\Schematic\Serialization;

use Psr\Container\ContainerInterface;

/**
 * An {@see ExtractorResolver} that resolves extractors from a PSR-11 container (falling back to `new` for
 * extractors the container does not know), enabling extractors with dependencies.
 */
final class ContainerExtractorResolver implements ExtractorResolver
{
    private readonly ExtractorResolver $fallback;

    public function __construct(
        private readonly ContainerInterface $container,
        ExtractorResolver|null $fallback = null,
    ) {
        $this->fallback = $fallback ?? new NewExtractorResolver();
    }

    public function resolve(string $extractorClassName): ValueExtractor
    {
        if (!$this->container->has($extractorClassName)) {
            return $this->fallback->resolve($extractorClassName);
        }
        $extractor = $this->container->get($extractorClassName);
        if (!$extractor instanceof ValueExtractor) {
            throw new \InvalidArgumentException(sprintf('The container returned a %s for "%s", which is not a %s', get_debug_type($extractor), $extractorClassName, ValueExtractor::class), 1751884912);
        }
        return $extractor;
    }
}
