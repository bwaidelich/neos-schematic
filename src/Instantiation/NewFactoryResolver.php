<?php

declare(strict_types=1);

namespace Neos\Schematic\Instantiation;

/**
 * The default {@see FactoryResolver}: instantiates the factory via `new` (factories must be stateless / have no
 * required constructor arguments). Use {@see ContainerFactoryResolver} for factories with dependencies.
 */
final class NewFactoryResolver implements FactoryResolver
{
    public function resolve(string $factoryClassName): InstanceFactory
    {
        if (!class_exists($factoryClassName)) {
            throw new \InvalidArgumentException(sprintf('InstanceFactory class "%s" does not exist', $factoryClassName), 1751884900);
        }
        // checked before instantiating: constructing an arbitrary class first would surface a misconfiguration as
        // whatever that constructor happens to throw (e.g. ArgumentCountError) instead of this message
        if (!is_a($factoryClassName, InstanceFactory::class, true)) {
            throw new \InvalidArgumentException(sprintf('Class "%s" is not a %s', $factoryClassName, InstanceFactory::class), 1751884901);
        }
        return new $factoryClassName();
    }
}
