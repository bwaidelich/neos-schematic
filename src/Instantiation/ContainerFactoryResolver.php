<?php

declare(strict_types=1);

namespace Neos\Schematic\Instantiation;

use Psr\Container\ContainerInterface;

/**
 * A {@see FactoryResolver} that resolves factories from a PSR-11 container (falling back to `new` for factories
 * the container does not know), enabling factories with dependencies.
 */
final class ContainerFactoryResolver implements FactoryResolver
{
    private readonly FactoryResolver $fallback;

    public function __construct(
        private readonly ContainerInterface $container,
        FactoryResolver|null $fallback = null,
    ) {
        $this->fallback = $fallback ?? new NewFactoryResolver();
    }

    public function resolve(string $factoryClassName): InstanceFactory
    {
        if (!$this->container->has($factoryClassName)) {
            return $this->fallback->resolve($factoryClassName);
        }
        $factory = $this->container->get($factoryClassName);
        if (!$factory instanceof InstanceFactory) {
            throw new \InvalidArgumentException(sprintf('The container returned a %s for "%s", which is not a %s', get_debug_type($factory), $factoryClassName, InstanceFactory::class), 1751884902);
        }
        return $factory;
    }
}
