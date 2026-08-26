<?php

declare(strict_types=1);

namespace Neos\Schematic\Generation\Middleware;

use Neos\Schematic\Generation\SchemaGenerator;
use Neos\Schematic\Generation\SchemaMiddleware;
use Neos\Schematic\Schema\Schema;
use Psr\SimpleCache\CacheInterface;

/**
 * Caches produced {@see Schema} graphs in a PSR-16 cache to avoid re-running the (reflection-based) generation
 * middleware behind it. Place this first in the chain so it fronts everything; because nested types re-enter the
 * root pipeline, each named type is cached independently.
 *
 * The Schema graph is serializable (no closures, no reflection handles), so it is stored via {@see serialize()}.
 */
final class CachingMiddleware implements SchemaMiddleware
{
    /**
     * Part of the cache key, so entries written by an older shape of {@see Schema} are never read back.
     *
     * `unserialize()` of an entry that predates a newly added property yields an object that still satisfies
     * `instanceof Schema` but has that property *uninitialized* — the read then fails with a fatal `Error`
     * somewhere far away from here. **Bump this whenever `Schema`'s serialized shape changes.**
     */
    private const FORMAT_VERSION = 4;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $keyPrefix = 'neos_schematic_',
    ) {}

    public function process(string $className, SchemaGenerator $next, SchemaGenerator $root): Schema
    {
        $key = $this->keyPrefix . self::FORMAT_VERSION . '_' . md5($className);
        $cached = $this->cache->get($key);
        if (is_string($cached)) {
            $schema = @unserialize($cached);
            if ($schema instanceof Schema) {
                return $schema;
            }
        }
        $schema = $next->generate($className);
        $this->cache->set($key, serialize($schema));
        return $schema;
    }
}
