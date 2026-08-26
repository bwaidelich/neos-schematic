<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Generation;

use Neos\Schematic\Generation\CircularReferenceException;
use Neos\Schematic\Generation\Middleware\CachingMiddleware;
use Neos\Schematic\Generation\SchemaFactory;
use Neos\Schematic\Generation\UnsupportedTypeException;
use Neos\Schematic\Tests\Fixtures\ArrayCache;
use Neos\Schematic\Tests\Fixtures\Contact;
use Neos\Schematic\Tests\Fixtures\ContactMiddleware;
use Neos\Schematic\Tests\Fixtures\ContactName;
use Neos\Schematic\Tests\Fixtures\ProductId;
use Neos\Schematic\Tests\Fixtures\RecursiveMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * The guard in `SchemaFactory`, exercised engine-only (no reflecting middleware): a `Schema` node holds its
 * children *directly*, so it cannot represent a cycle, and the caching middleware cannot break one either — it
 * writes its entry only *after* generation finishes, so the recursive call never finds one to stop at. Without the
 * guard the pipeline exhausts the stack, which is a crash rather than a diagnosis.
 */
final class CircularReferenceTest extends TestCase
{
    public function testAClassThatDescendsIntoItselfIsRejected(): void
    {
        $factory = new SchemaFactory(new RecursiveMiddleware([Contact::class => Contact::class]));

        $this->expectException(CircularReferenceException::class);
        $factory->for(Contact::class);
    }

    /**
     * A cycle through two classes needs the whole generation stack, not just "am I my own child".
     */
    public function testTheMessageNamesTheWholeCyclePath(): void
    {
        $factory = new SchemaFactory(new RecursiveMiddleware([
            Contact::class => ContactName::class,
            ContactName::class => Contact::class,
        ]));

        try {
            $factory->for(Contact::class);
            self::fail('Expected a CircularReferenceException');
        } catch (CircularReferenceException $exception) {
            self::assertSame(
                sprintf(
                    'Circular reference while generating a schema: %s -> %s -> %s. Recursive types are not supported.',
                    Contact::class,
                    ContactName::class,
                    Contact::class,
                ),
                $exception->getMessage(),
            );
        }
    }

    public function testACycleIsDetectedFromEitherEntryPoint(): void
    {
        $factory = new SchemaFactory(new RecursiveMiddleware([
            Contact::class => ContactName::class,
            ContactName::class => Contact::class,
        ]));

        $this->expectException(CircularReferenceException::class);
        $factory->for(ContactName::class);
    }

    /**
     * The same class reached twice *side by side* is a diamond, not a cycle: the first generation completes and is
     * cleared before the second starts.
     */
    public function testTheSameClassReachedTwiceSequentiallyIsNotACycle(): void
    {
        $factory = new SchemaFactory(new ContactMiddleware());

        self::assertSame(Contact::class, $factory->for(Contact::class)->target);
        self::assertSame(Contact::class, $factory->for(Contact::class)->target);
    }

    /**
     * The in-progress marker is cleared in a `finally`, so a *failed* generation must not poison later attempts —
     * otherwise the second call would be misreported as a cycle rather than repeating the real error.
     */
    public function testAFailedGenerationDoesNotLeaveTheClassMarked(): void
    {
        $factory = new SchemaFactory();

        foreach (range(1, 2) as $attempt) {
            try {
                $factory->for(Contact::class);
                self::fail('Expected an UnsupportedTypeException on attempt ' . $attempt);
            } catch (UnsupportedTypeException $exception) {
                // the same error both times, never a CircularReferenceException
                self::assertStringContainsString(Contact::class, $exception->getMessage());
            }
        }
    }

    public function testACycleIsStillDetectedBehindTheCachingMiddleware(): void
    {
        $factory = new SchemaFactory(
            new CachingMiddleware(new ArrayCache()),
            new RecursiveMiddleware([Contact::class => Contact::class]),
        );

        $this->expectException(CircularReferenceException::class);
        $factory->for(Contact::class);
    }

    /**
     * The recursive class itself is never cached (its generation never completes), but a nested type that *did*
     * finish before the cycle was hit is cached as usual — a half-built graph is never stored.
     */
    public function testTheRecursiveClassItselfIsNotCached(): void
    {
        $cache = new ArrayCache();
        $factory = new SchemaFactory(
            new CachingMiddleware($cache),
            new RecursiveMiddleware([Contact::class => ProductId::class]),
            new \Neos\Schematic\Generation\Middleware\ProvidesSchemaMiddleware(),
        );

        // ProductId resolves and is cached; then make Contact recurse into itself on a fresh factory
        $factory->for(Contact::class);
        self::assertNotSame([], $cache->entries());

        $recursing = new SchemaFactory(
            new CachingMiddleware($cache),
            new RecursiveMiddleware([ContactName::class => ContactName::class]),
        );
        try {
            $recursing->for(ContactName::class);
            self::fail('Expected a CircularReferenceException');
        } catch (CircularReferenceException) {
            // expected
        }

        foreach ($cache->entries() as $entry) {
            self::assertIsString($entry);
            self::assertStringNotContainsString(ContactName::class, $entry);
        }
    }
}
