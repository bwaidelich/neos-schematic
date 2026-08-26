<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Generation;

use Neos\Schematic\Generation\Middleware\CachingMiddleware;
use Neos\Schematic\Generation\SchemaFactory;
use Neos\Schematic\Generation\UnsupportedTypeException;
use Neos\Schematic\Tests\Fixtures\ArrayCache;
use Neos\Schematic\Tests\Fixtures\Contact;
use Neos\Schematic\Tests\Fixtures\ContactMiddleware;
use PHPUnit\Framework\TestCase;

final class SchemaFactoryTest extends TestCase
{
    public function testProducesSchemaViaMiddleware(): void
    {
        $factory = new SchemaFactory(new ContactMiddleware());

        self::assertSame(Contact::class, $factory->for(Contact::class)->target);
    }

    public function testThrowsForUnhandledClass(): void
    {
        $factory = new SchemaFactory(new ContactMiddleware());

        $this->expectException(UnsupportedTypeException::class);
        $factory->for(\stdClass::class);
    }

    public function testCachesGeneratedSchemaAcrossCalls(): void
    {
        $cache = new ArrayCache();
        $factory = new SchemaFactory(new CachingMiddleware($cache), new ContactMiddleware());

        $first = $factory->for(Contact::class);
        self::assertSame(0, $cache->hits);
        self::assertSame(1, $cache->misses);

        $second = $factory->for(Contact::class);
        self::assertSame(1, $cache->hits);
        self::assertEquals($first, $second);
    }

    /**
     * Entries written by an older shape of `Schema` must never be read back: `unserialize()` would produce an
     * object that still satisfies `instanceof Schema` but has any newly added property *uninitialized*, turning
     * the next read into a fatal `Error` far from the cache. The format version in the key prevents the hit.
     */
    public function testIgnoresEntriesWrittenByAnEarlierSchemaFormat(): void
    {
        $cache = new ArrayCache();
        $factory = new SchemaFactory(new CachingMiddleware($cache), new ContactMiddleware());
        $factory->for(Contact::class);

        // re-key every entry as if an older format version had written it, whatever the current version is
        $staleCache = new ArrayCache();
        foreach ($cache->entries() as $key => $value) {
            $staleKey = preg_replace('/^neos_schematic_\d+_/', 'neos_schematic_0_', $key);
            self::assertIsString($staleKey);
            self::assertNotSame($key, $staleKey, 'the cache key is expected to carry a format version');
            $staleCache->set($staleKey, $value);
        }
        $staleFactory = new SchemaFactory(new CachingMiddleware($staleCache), new ContactMiddleware());

        $schema = $staleFactory->for(Contact::class);

        self::assertSame(0, $staleCache->hits);
        self::assertFalse($schema->nullable);
    }
}
