<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Schema;

use Neos\Schematic\Instantiation\InstanceFactory;
use Neos\Schematic\Instantiation\Instantiator;
use Neos\Schematic\Schema\Schema;

/**
 * Proves by construction that the Instantiator never reaches the factory for a `null` value on a nullable node.
 */
final class ExplodingFactory implements InstanceFactory
{
    public function instantiate(Schema $schema, mixed $value, Instantiator $instantiator): mixed
    {
        throw new \LogicException('The InstanceFactory must not be invoked for a null value on a nullable node');
    }
}
