<?php

declare(strict_types=1);

namespace Neos\Schematic\Serialization;

use Neos\Schematic\Schema\Schema;

/**
 * Reads a target instance back out into schema-conformant primitives — the mirror image of
 * {@see \Neos\Schematic\Instantiation\InstanceFactory}.
 *
 * A ValueExtractor is referenced by class-string on a {@see Schema} node (so the Schema stays serializable) and
 * recurses into children via the injected {@see Serializer}. Traversal is driven by the extractors, not by the
 * Serializer, so a novel Schema shape needs no framework-level traversal support.
 */
interface ValueExtractor
{
    /**
     * @param Schema $schema The node being serialized (provides the target class + child schemas)
     * @param mixed $value The target instance for this node (children are still instances)
     * @param Serializer $serializer The recurse handle, to read child values
     * @throws UnextractableValue if the target does not expose the state this node describes
     */
    public function extract(Schema $schema, mixed $value, Serializer $serializer): mixed;
}
