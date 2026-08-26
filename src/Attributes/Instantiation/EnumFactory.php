<?php

declare(strict_types=1);

namespace Neos\Schematic\Attributes\Instantiation;

use BackedEnum;
use Neos\Schematic\Coercion\InvalidValue;
use Neos\Schematic\Instantiation\InstanceFactory;
use Neos\Schematic\Instantiation\Instantiator;
use Neos\Schematic\Schema\Schema;
use UnitEnum;

/**
 * Resolves a coerced scalar to an enum case: a *backed* enum by its backing value, a *pure* enum by its case name
 * — mirroring how {@see \Neos\Schematic\Attributes\ReflectionMiddleware} describes each.
 *
 * Unlike the other factories this one constructs nothing: enum cases are singletons, so it looks the case up
 * rather than building it.
 */
final class EnumFactory implements InstanceFactory
{
    public function instantiate(Schema $schema, mixed $value, Instantiator $instantiator): mixed
    {
        $target = $schema->target;
        if ($target === null || !is_subclass_of($target, UnitEnum::class)) {
            throw new \LogicException(sprintf('%s requires the schema node to target an enum', self::class), 1751885107);
        }
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidValue(sprintf('Expected a string or integer to resolve an enum case, got %s', get_debug_type($value)), []);
        }
        if (is_subclass_of($target, BackedEnum::class)) {
            return $target::tryFrom($value) ?? throw new InvalidValue(sprintf('Value "%s" is not a case of enum "%s"', $value, $target), []);
        }
        foreach ($target::cases() as $case) {
            if ($case->name === $value) {
                return $case;
            }
        }
        throw new InvalidValue(sprintf('Value "%s" is not a case of enum "%s"', $value, $target), []);
    }
}
