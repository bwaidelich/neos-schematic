<?php

declare(strict_types=1);

namespace Neos\Schematic\Instantiation;

/**
 * Resolves a {@see InstanceFactory} instance from the class-string named on a Schema node.
 */
interface FactoryResolver
{
    /**
     * @param class-string $factoryClassName
     */
    public function resolve(string $factoryClassName): InstanceFactory;
}
