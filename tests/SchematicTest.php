<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests;

use Neos\JsonSchema\Validation\IssueCode;
use Neos\Schematic\CoercionFailed;
use Neos\Schematic\Schematic;
use Neos\Schematic\Tests\Fixtures\Adopting;
use Neos\Schematic\Tests\Fixtures\Author;
use Neos\Schematic\Tests\Fixtures\Channel;
use Neos\Schematic\Tests\Fixtures\Forgetful;
use Neos\Schematic\Tests\Fixtures\Hidden;
use Neos\Schematic\Tests\Fixtures\Post;
use Neos\Schematic\Tests\Fixtures\Rating;
use Neos\Schematic\Tests\Fixtures\Reclusive;
use Neos\Schematic\Tests\Fixtures\Slug;
use Neos\Schematic\Tests\Fixtures\Status;
use Neos\Schematic\Tests\Fixtures\Tag;
use Neos\Schematic\Tests\Fixtures\Tags;
use Neos\Schematic\Tests\Fixtures\Trapped;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The facade, end to end: a schema taken from the class, an instance built through it, and the same instance read
 * back out.
 */
#[CoversClass(Schematic::class)]
#[CoversClass(CoercionFailed::class)]
#[CoversClass(\Neos\Schematic\Instantiation\Instantiator::class)]
final class SchematicTest extends TestCase
{
    /**
     * @return array<mixed>
     */
    private function payload(): array
    {
        return [
            'slug' => 'hello-world',
            'author' => 'Ada',
            'status' => 'draft',
            'tags' => ['php', 'json'],
            'subtitle' => null,
        ];
    }

    /**
     * A class that provides no schema cannot be *named* to the facade at all: `instanciate()` takes a
     * `class-string<ProvidesSchema>`, so writing one down is a type error rather than something to report at
     * runtime. Where a class name arrives untyped there is nothing to read it off but reflection, and the descent
     * into a member is the one such place — refused there, in the terms that say what to implement.
     */
    public function testTheRefusalReachesTheDescentIntoAMember(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/provides no schema, so there is nothing to build it against/');
        // the result is never produced: the call throws before it can return one
        $unreached = Schematic::instanciate(Adopting::class, ['held' => ['value' => 'anything']]);
    }

    /**
     * A schema is class-level and immutable, so building it once is worth doing — and it is the class's own
     * business, said with a `static` in `schema()`. `Slug` does; `Forgetful` deliberately does not, and is asked
     * again every time. Nothing here holds a schema on a class's behalf.
     */
    public function testAClassDecidesForItselfWhetherItsSchemaIsBuiltOnce(): void
    {
        self::assertSame(Slug::schema(), Slug::schema());

        Forgetful::$calls = 0;
        $first = Forgetful::schema();
        $second = Forgetful::schema();

        self::assertNotSame($first, $second);
        self::assertEquals($first, $second);
        self::assertSame(2, Forgetful::$calls);
    }

    public function testANamedConstructorReachesItsOwnPrivateConstructor(): void
    {
        $slug = Slug::fromString('hello-world');

        self::assertSame('hello-world', $slug->value);
    }

    /**
     * Construction is the constructor, whatever its visibility: implementing `ProvidesSchema` is how a class opts
     * in, and `private` fences off `new` from elsewhere rather than fencing off the engine the class invited.
     */
    public function testTheOrdinaryEntryPointReachesAPrivateConstructor(): void
    {
        self::assertEquals(Slug::fromString('hello-world'), Schematic::instanciate(Slug::class, 'hello-world')->valueOrThrow());
    }

    /**
     * It validates like every other path — reaching a private constructor is never a bypass of the schema.
     */
    public function testReachingAPrivateConstructorStillValidates(): void
    {
        $this->expectException(CoercionFailed::class);
        Schematic::instanciate(Slug::class, 'Hello World')->valueOrThrow();
    }

    public function testAShapeReachesAPrivateConstructorByNamedArguments(): void
    {
        $hidden = Schematic::instanciate(Hidden::class, 'secret')->valueOrThrow();

        self::assertSame('secret', $hidden->reveal());
    }

    /**
     * The engine never calls a `from*()` named constructor. One that calls back into the facade — the documented
     * shape — would otherwise validate the same value twice per level of nesting, and recurse without end.
     */
    public function testTheEngineNeverCallsANamedConstructor(): void
    {
        $trapped = Schematic::instanciate(Trapped::class, 'quiet')->valueOrThrow();

        self::assertSame('quiet', $trapped->value);
    }

    /**
     * The invariant holds however the value was built, so the named constructor validates too — that is the whole
     * reason instantiation goes through the schema rather than around it.
     */
    public function testANamedConstructorEnforcesTheClassSchema(): void
    {
        $this->expectException(CoercionFailed::class);
        $this->expectExceptionMessageMatches('/does not match the pattern/');
        Slug::fromString('Hello World');
    }

    public function testTheIssuesSurviveOnTheException(): void
    {
        try {
            Slug::fromString('');
            self::fail('expected the construction to fail');
        } catch (CoercionFailed $exception) {
            self::assertSame([IssueCode::TooShort->value], $exception->issues->codes());
        }
    }

    public function testAClassWithAPublicConstructorIsBuiltDirectly(): void
    {
        $author = Schematic::instanciate(Author::class, 'Ada')->valueOrThrow();

        self::assertInstanceOf(Author::class, $author);
        self::assertSame('Ada', $author->name);
    }

    /**
     * No named constructor either: nothing about the class has to be arranged for the engine beyond the schema
     * it owns.
     */
    public function testAPrivateConstructorWithoutANamedConstructorIsStillBuilt(): void
    {
        $reclusive = Schematic::instanciate(Reclusive::class, 'nope')->valueOrThrow();

        self::assertSame('nope', $reclusive->value);
    }

    public function testAWholeShapeIsBuiltFromAPayload(): void
    {
        $result = Schematic::instanciate(Post::class, $this->payload());

        self::assertTrue($result->success, (string) $result->issues);
        $post = $result->value();
        self::assertInstanceOf(Post::class, $post);
        self::assertSame('hello-world', $post->slug->value);
        self::assertSame('Ada', $post->author->name);
        self::assertSame(Status::draft, $post->status);
        self::assertSame(['php', 'json'], array_map(static fn(Tag $tag): string => $tag->value, $post->tags->tags));
        self::assertNull($post->subtitle);
    }

    /**
     * A nested type is built by *its own* named constructor, so `Slug` inside a `Post` is the same `Slug` as one
     * built directly — there is no second, unvalidated construction path.
     */
    public function testANestedValueObjectGoesThroughItsOwnNamedConstructor(): void
    {
        $post = Schematic::instanciate(Post::class, $this->payload())->value();

        self::assertInstanceOf(Post::class, $post);
        self::assertEquals(Slug::fromString('hello-world'), $post->slug);
    }

    public function testAnOptionalPropertyMayBeOmittedEntirely(): void
    {
        $payload = $this->payload();
        unset($payload['subtitle']);

        $post = Schematic::instanciate(Post::class, $payload)->value();

        self::assertInstanceOf(Post::class, $post);
        self::assertNull($post->subtitle);
    }

    /**
     * The point of validating the whole payload first: a bad request reports everything wrong with it at once,
     * each issue located where it belongs.
     */
    public function testEveryIssueInAPayloadIsReportedAtItsOwnPath(): void
    {
        $result = Schematic::instanciate(Post::class, ['slug' => 'Hello World', 'author' => 'Ada', 'status' => 'nope', 'tags' => []]);

        self::assertFalse($result->success);
        self::assertSame(['slug', 'status'], array_map(static fn($issue): string => $issue->pathAsString(), $result->issues->toArray()));
    }

    public function testInstanciateReportsIssuesInsteadOfThrowing(): void
    {
        $result = Schematic::instanciate(Slug::class, 'Hello World');

        self::assertFalse($result->success);
        self::assertSame([IssueCode::InvalidPattern->value], $result->issues->codes());
    }

    public function testABackedEnumIsResolvedByItsValue(): void
    {
        self::assertSame(Rating::high, Schematic::instanciate(Rating::class, 5)->valueOrThrow());
    }

    public function testAPureEnumIsResolvedByItsCaseName(): void
    {
        self::assertSame(Channel::phone, Schematic::instanciate(Channel::class, 'phone')->valueOrThrow());
    }

    public function testACollectionIsBuiltFromAList(): void
    {
        $tags = Schematic::instanciate(Tags::class, ['php', 'json'])->valueOrThrow();

        self::assertInstanceOf(Tags::class, $tags);
        self::assertSame(['php', 'json'], array_map(static fn(Tag $tag): string => $tag->value, $tags->tags));
    }

    /**
     * A value is judged exactly as it arrives: `"5"` is a string, and a string is not a case of an integer-backed
     * enum. Reading a parameter that is *always* a string leniently is the binding layer's business — see
     * `Neos\OpenApi\Binding\ScalarNormalizer` — and it happens before the value gets here.
     */
    public function testAValueIsJudgedExactlyAsItArrives(): void
    {
        self::assertSame(Rating::high, Schematic::instanciate(Rating::class, 5)->valueOrThrow());
        self::assertFalse(Schematic::instanciate(Rating::class, '5')->success);
    }

    /**
     * One input shape, so that a payload that validates is one that builds: JSON decoded to associative arrays.
     */
    public function testAStdClassPayloadIsReportedRatherThanUnwrapped(): void
    {
        $result = Schematic::instanciate(Post::class, (object) $this->payload());

        self::assertFalse($result->success);
        self::assertSame([IssueCode::InvalidType->value], $result->issues->codes());
    }
}
