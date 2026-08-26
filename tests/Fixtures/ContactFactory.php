<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\Schematic\Instantiation\InstanceFactory;
use Neos\Schematic\Instantiation\Instantiator;
use Neos\Schematic\Schema\Kind;
use Neos\Schematic\Schema\Schema;

final class ContactFactory implements InstanceFactory
{
    public function instantiate(Schema $schema, mixed $value, Instantiator $instantiator): mixed
    {
        if ($schema->kind !== Kind::Object) {
            throw new \LogicException('ContactFactory expects an object schema');
        }
        assert(is_array($value));
        $name = $instantiator->instantiate($schema->properties['name'], $value['name'] ?? null);
        $age = $instantiator->instantiate($schema->properties['age'], $value['age'] ?? null);
        assert($name instanceof ContactName);
        assert($age instanceof ContactAge);
        return new Contact($name, $age);
    }
}
