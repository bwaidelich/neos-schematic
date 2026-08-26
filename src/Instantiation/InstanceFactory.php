<?php

declare(strict_types=1);

namespace Neos\Schematic\Instantiation;

use Neos\Schematic\Coercion\InvalidValue;
use Neos\Schematic\Schema\Schema;

/**
 * Assembles a target instance from an already-coerced value.
 *
 * A InstanceFactory is referenced by class-string on a {@see Schema} node (so the Schema stays serializable). It recurses
 * into child values via the injected {@see Instantiator}. To signal an invariant the schema cannot express, it
 * throws {@see InvalidValue}; any other exception is treated as a genuine bug.
 */
interface InstanceFactory
{
    /**
     * @param Schema $schema The node being instantiated (provides the target class + child schemas)
     * @param mixed $value The coerced value for this node (children are still coerced primitives)
     * @param Instantiator $instantiator The recurse handle, to build child values
     * @throws InvalidValue
     */
    public function instantiate(Schema $schema, mixed $value, Instantiator $instantiator): mixed;
}
