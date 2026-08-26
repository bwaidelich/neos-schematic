<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\Schematic\Instantiation\InstanceFactory;
use Neos\Schematic\Instantiation\Instantiator;
use Neos\Schematic\Schema\Kind;
use Neos\Schematic\Schema\Schema;

final class NullableContactFactory implements InstanceFactory
{
    public function instantiate(Schema $schema, mixed $value, Instantiator $instantiator): mixed
    {
        if ($schema->kind !== Kind::Object) {
            throw new \LogicException('NullableContactFactory expects an object schema');
        }
        assert(is_array($value));
        $name = $instantiator->instantiate($schema->properties['name'], $value['name'] ?? null);
        $nickname = $instantiator->instantiate($schema->properties['nickname'], $value['nickname'] ?? null);
        $note = $instantiator->instantiate($schema->properties['note'], $value['note'] ?? null);
        assert($name instanceof ContactName);
        assert($nickname === null || $nickname instanceof ContactName);
        assert($note === null || is_string($note));
        return new NullableContact($name, $nickname, $note);
    }
}
