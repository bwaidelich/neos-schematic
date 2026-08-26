<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\Schematic\Coercion\InvalidValue;
use Neos\Schematic\Instantiation\InstanceFactory;
use Neos\Schematic\Instantiation\Instantiator;
use Neos\Schematic\Schema\Schema;

/**
 * Demonstrates the adapter pattern: it catches the domain object's native \InvalidArgumentException and re-throws
 * it as the {@see InvalidValue} marker so the Instantiator turns it into an Issue.
 */
final class DateRangeFactory implements InstanceFactory
{
    public function instantiate(Schema $schema, mixed $value, Instantiator $instantiator): mixed
    {
        assert(is_array($value));
        $start = $value['start'] ?? null;
        $end = $value['end'] ?? null;
        assert(is_int($start) && is_int($end));
        try {
            return new DateRange($start, $end);
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidValue($exception->getMessage(), [], $exception);
        }
    }
}
