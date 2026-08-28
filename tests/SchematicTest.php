<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests;

use Neos\JsonSchema\Validation\IssueCode;
use Neos\JsonSchema\Validation\Normalization;
use Neos\Schematic\CoercionFailed;
use Neos\Schematic\SchemaNotProvided;
use Neos\Schematic\Schematic;
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
use Neos\Schematic\Tests\Fixtures\WithoutSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The facade, end to end: a schema taken from the class, an instance built through it, and the same instance read
 * back out.
 */
#[CoversClass(Schematic::class)]
#[CoversClass(CoercionFailed::class)]
#[CoversClass(SchemaNotProvided::class)]
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

    public function testATypeProvidingASchemaKeepsIt(): void
    {
        self::assertSame(Slug::schema(), Schematic::schemaFor(Slug::class));
    }

    public function testAClassIsAskedForItsOwnSchema(): void
    {
        self::assertSame(['type' => 'string'], json_decode((string) json_encode(Schematic::schemaFor(Author::class)), true));
    }

    /**
     * Owning a schema is opt-in. Deriving one for a class that never asked would make the published contract an
     * accident of its constructor, so a class that does not provide one is refused instead.
     */
    public function testAClassThatProvidesNoSchemaIsRefused(): void
    {
        $this->expectException(SchemaNotProvided::class);
        $this->expectExceptionMessageMatches('/provides no schema: implement/');
        Schematic::schemaFor(WithoutSchema::class);
    }

    public function testTheRefusalReachesEveryEntryPoint(): void
    {
        $this->expectException(SchemaNotProvided::class);
        // the result is never produced: the call throws before it can return one
        $unreached = Schematic::map(WithoutSchema::class, ['value' => 'anything']);
    }

    /**
     * A schema is class-level and immutable, so building it once is worth doing — and it is the class's own
     * business, said with a `static` in `schema()`. `Slug` does; `Forgetful` deliberately does not, and is asked
     * again every time. Nothing here holds a schema on a class's behalf.
     */
    public function testAClassDecidesForItselfWhetherItsSchemaIsBuiltOnce(): void
    {
        self::assertSame(Schematic::schemaFor(Slug::class), Schematic::schemaFor(Slug::class));

        Forgetful::$calls = 0;
        $first = Schematic::schemaFor(Forgetful::class);
        $second = Schematic::schemaFor(Forgetful::class);

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
        self::assertEquals(Slug::fromString('hello-world'), Schematic::instantiate(Slug::class, 'hello-world'));
    }

    /**
     * It validates like every other path — reaching a private constructor is never a bypass of the schema.
     */
    public function testReachingAPrivateConstructorStillValidates(): void
    {
        $this->expectException(CoercionFailed::class);
        Schematic::instantiate(Slug::class, 'Hello World');
    }

    public function testAShapeReachesAPrivateConstructorByNamedArguments(): void
    {
        $hidden = Schematic::instantiate(Hidden::class, 'secret');

        self::assertSame('secret', $hidden->reveal());
    }

    /**
     * The engine never calls a `from*()` named constructor. One that calls back into the facade — the documented
     * shape — would otherwise validate the same value twice per level of nesting, and recurse without end.
     */
    public function testTheEngineNeverCallsANamedConstructor(): void
    {
        $trapped = Schematic::instantiate(Trapped::class, 'quiet');

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
        $author = Schematic::instantiate(Author::class, 'Ada');

        self::assertInstanceOf(Author::class, $author);
        self::assertSame('Ada', $author->name);
    }

    /**
     * No named constructor either: nothing about the class has to be arranged for the engine beyond the schema
     * it owns.
     */
    public function testAPrivateConstructorWithoutANamedConstructorIsStillBuilt(): void
    {
        $reclusive = Schematic::instantiate(Reclusive::class, 'nope');

        self::assertSame('nope', $reclusive->value);
    }

    public function testAWholeShapeIsBuiltFromAPayload(): void
    {
        $result = Schematic::map(Post::class, $this->payload());

        self::assertTrue($result->valid, (string) $result->issues);
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
        $post = Schematic::map(Post::class, $this->payload())->value();

        self::assertInstanceOf(Post::class, $post);
        self::assertEquals(Slug::fromString('hello-world'), $post->slug);
    }

    public function testAnOptionalPropertyMayBeOmittedEntirely(): void
    {
        $payload = $this->payload();
        unset($payload['subtitle']);

        $post = Schematic::map(Post::class, $payload)->value();

        self::assertInstanceOf(Post::class, $post);
        self::assertNull($post->subtitle);
    }

    /**
     * The point of validating the whole payload first: a bad request reports everything wrong with it at once,
     * each issue located where it belongs.
     */
    public function testEveryIssueInAPayloadIsReportedAtItsOwnPath(): void
    {
        $result = Schematic::map(Post::class, ['slug' => 'Hello World', 'author' => 'Ada', 'status' => 'nope', 'tags' => []]);

        self::assertFalse($result->valid);
        self::assertSame(['slug', 'status'], array_map(static fn($issue): string => $issue->pathAsString(), $result->issues->toArray()));
    }

    public function testMapReportsIssuesInsteadOfThrowing(): void
    {
        $result = Schematic::map(Slug::class, 'Hello World');

        self::assertFalse($result->valid);
        self::assertSame([IssueCode::InvalidPattern->value], $result->issues->codes());
    }

    public function testABackedEnumIsResolvedByItsValue(): void
    {
        self::assertSame(Rating::high, Schematic::instantiate(Rating::class, 5));
    }

    public function testAPureEnumIsResolvedByItsCaseName(): void
    {
        self::assertSame(Channel::phone, Schematic::instantiate(Channel::class, 'phone'));
    }

    public function testACollectionIsBuiltFromAList(): void
    {
        $tags = Schematic::instantiate(Tags::class, ['php', 'json']);

        self::assertInstanceOf(Tags::class, $tags);
        self::assertSame(['php', 'json'], array_map(static fn(Tag $tag): string => $tag->value, $tags->tags));
    }

    /**
     * A path or query parameter is always a string, so its binding site opts into scalar normalization; a JSON
     * body does not, and `"5"` stays a string there.
     */
    public function testNormalizationIsChosenPerCallSite(): void
    {
        self::assertSame(Rating::high, Schematic::instantiate(Rating::class, '5', normalization: Normalization::Scalars));
        self::assertFalse(Schematic::map(Rating::class, '5')->valid);
    }
}
