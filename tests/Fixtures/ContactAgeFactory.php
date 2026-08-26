<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\Schematic\Instantiation\InstanceFactory;
use Neos\Schematic\Instantiation\Instantiator;
use Neos\Schematic\Schema\Schema;

final class ContactAgeFactory implements InstanceFactory
{
    public function instantiate(Schema $schema, mixed $value, Instantiator $instantiator): mixed
    {
        assert(is_int($value));
        return new ContactAge($value);
    }
}
