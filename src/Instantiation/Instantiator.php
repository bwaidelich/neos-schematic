<?php

declare(strict_types=1);

namespace Neos\Schematic\Instantiation;

use Neos\JsonSchema\ProvidesSchema;
use Neos\Schematic\Schematic;

/**
 * Builds an instance from **already validated** primitives - one link in the chain that does it.
 *
 * Each link is able to build one kind of thing and hands everything else to the next:
 * new instantiators (e.g. for `DateTimeImmutable`, or other special cases) are added in front of the built-in ones.
 * The default chain is assembled in {@see Schematic::instantiate()} and ends in {@see UnbuildableInstantiator},
 * which fails with an exception.
 *
 * Schema Validation should happen before the chain runs.
 *
 * **The constructor is called for instantiating, even if it is private.**.
 *
 * The engine deliberately does *not* call `from*()` named constructors, as that is reserved for external building,
 * where one can call {@see Schematic::instantiate()}.
 *
 * **`$root` must be the chain itself**, handed to every link so that recursion re-enters at the top rather
 * than continuing where it left off.
 *
 * @api
 */
interface Instantiator
{
    /**
     * @template T of ProvidesSchema
     * @param class-string<T> $className a class owning a schema — {@see \Neos\JsonSchema\ProvidesSchema}
     * @param mixed $value primitives that already satisfy that schema
     * @param Instantiator $root the whole chain, for descending into members
     * @return T
     */
    public function build(string $className, mixed $value, Instantiator $root): object;
}
