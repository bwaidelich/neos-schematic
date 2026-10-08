<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests;

use Neos\JsonSchema\Validation\IssueCode;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;
use Neos\Schematic\Discovery\UnsupportedTypeException;
use Neos\Schematic\Exception\InstantiationFailed;
use Neos\Schematic\Schematic;
use Neos\Schematic\Tests\Discovery\Fixtures\Status as DiscoveredStatus;
use Neos\Schematic\Tests\Discovery\Fixtures\Untyped;
use Neos\Schematic\Tests\Fixtures\Adopting;
use Neos\Schematic\Tests\Fixtures\Article;
use Neos\Schematic\Tests\Fixtures\ArticleOrSlug;
use Neos\Schematic\Tests\Fixtures\Author;
use Neos\Schematic\Tests\Fixtures\Channel;
use Neos\Schematic\Tests\Fixtures\Feature;
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
use Neos\Schematic\Tests\Fixtures\WithoutSchemaWithPrivateConstructor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The facade, end to end: a schema taken from the class, an instance built through it, and the same instance read
 * back out.
 */
#[CoversClass(Schematic::class)]
#[CoversClass(InstantiationFailed::class)]
#[CoversClass(\Neos\Schematic\Instantiation\EnumCaseInstantiator::class)]
#[CoversClass(\Neos\Schematic\Instantiation\ScalarValueInstantiator::class)]
#[CoversClass(\Neos\Schematic\Instantiation\ListOfItemsInstantiator::class)]
#[CoversClass(\Neos\Schematic\Instantiation\NamedShapeInstantiator::class)]
#[CoversClass(\Neos\Schematic\Instantiation\UnbuildableInstantiator::class)]
#[CoversClass(\Neos\Schematic\Instantiation\Helper\Construct::class)]
#[CoversClass(\Neos\Schematic\Instantiation\Helper\MemberType::class)]
#[CoversClass(\Neos\Schematic\Serialization\Serializer::class)]
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
     * A class that provides no schema *and* keeps its constructor private has a way in of its own that analysis
     * cannot see, so it is not described on its behalf — refused, in the terms that say what to implement, at the
     * top as well as on the descent into a member.
     */
    public function testAClassWithoutASchemaAndWithAPrivateConstructorIsRefused(): void
    {
        $this->expectException(UnsupportedTypeException::class);
        $this->expectExceptionMessageMatches('/provides no schema and its constructor is not public/');
        $unreached = Schematic::instantiate(WithoutSchemaWithPrivateConstructor::class, 'anything');
    }

    public function testTheRefusalReachesTheDescentIntoAMember(): void
    {
        $this->expectException(UnsupportedTypeException::class);
        $this->expectExceptionMessageMatches('/provides no schema and its constructor is not public/');
        // the result is never produced: the call throws before it can return one
        $unreached = Schematic::instantiate(Adopting::class, ['held' => ['value' => 'anything']]);
    }

    /**
     * A plain record — public constructor, no `schema()` — is described by analyzing it, and that derived schema
     * is what the payload is validated against and what the record is built by.
     */
    public function testARecordWithoutASchemaIsBuiltAgainstItsDerivedSchema(): void
    {
        $article = Schematic::instantiate(Article::class, ['slug' => 'hello-world', 'title' => 'Hello'])->valueOrThrow();

        self::assertEquals(new Article(Slug::fromString('hello-world'), 'Hello'), $article);
    }

    /**
     * The derived schema embeds the schemas of the members that own one, so one pass still reports every issue.
     */
    public function testARecordWithoutASchemaIsValidatedIncludingItsMembers(): void
    {
        $result = Schematic::instantiate(Article::class, ['slug' => 'Hello World', 'title' => 'Hello', 'extra' => true]);

        self::assertFalse($result->success);
        self::assertSame(['slug', '<root>'], array_map(static fn($issue): string => $issue->pathAsString(), $result->issues->toArray()));
    }

    public function testANestedRecordWithoutASchemaIsBuiltToo(): void
    {
        $feature = Schematic::instantiate(Feature::class, [
            'article' => ['slug' => 'hello-world', 'title' => 'Hello', 'subtitle' => 'World'],
            'tags' => ['php'],
            'status' => 'draft',
        ])->valueOrThrow();

        self::assertEquals(new Article(Slug::fromString('hello-world'), 'Hello', 'World'), $feature->article);
        self::assertSame(['php'], array_map(static fn(Tag $tag): string => $tag->value, $feature->tags->tags));
        self::assertSame(Status::draft, $feature->status);
    }

    public function testAUnionBranchWithoutASchemaIsChosenByItsDerivedSchema(): void
    {
        $asArticle = Schematic::instantiate(ArticleOrSlug::class, ['held' => ['slug' => 'hello-world', 'title' => 'Hello']])->valueOrThrow();
        $asSlug = Schematic::instantiate(ArticleOrSlug::class, ['held' => 'hello-world'])->valueOrThrow();

        self::assertInstanceOf(Article::class, $asArticle->held);
        self::assertEquals(Slug::fromString('hello-world'), $asSlug->held);
    }

    public function testAnEnumWithoutASchemaIsResolvedToo(): void
    {
        self::assertSame(DiscoveredStatus::published, Schematic::instantiate(DiscoveredStatus::class, 'published')->valueOrThrow());
    }

    /**
     * A record that cannot be described is a programming error, not bad input — so it throws rather than being
     * reported as an issue of the payload.
     */
    public function testARecordThatCannotBeDescribedThrows(): void
    {
        $this->expectException(UnsupportedTypeException::class);
        $unreached = Schematic::instantiate(Untyped::class, ['anything' => []]);
    }

    public function testTheSchemaOfAClassProvidingOneIsItsOwn(): void
    {
        self::assertSame(Slug::schema(), Schematic::schemaOf(Slug::class));
    }

    /**
     * Analysis depends on nothing but the class, so it is done once per class and process.
     */
    public function testTheSchemaOfAClassWithoutOneIsDerivedOnce(): void
    {
        self::assertEquals(AutoDiscoveringSchema::analyze(Article::class), Schematic::schemaOf(Article::class));
        self::assertSame(Schematic::schemaOf(Article::class), Schematic::schemaOf(Article::class));
    }

    public function testARecordWithoutASchemaRoundTrips(): void
    {
        $payload = [
            'article' => ['slug' => 'hello-world', 'title' => 'Hello', 'subtitle' => null],
            'tags' => ['php'],
            'status' => 'draft',
        ];

        self::assertSame($payload, Schematic::serialize(Schematic::instantiate(Feature::class, $payload)->valueOrThrow()));
    }

    /**
     * A schema is class-level and immutable, so building it once is worth doing — and it is the class's own
     * business, said with a `static` in `schema()`. `Slug` does; `Forgetful` deliberately does not, and is asked
     * again every time. Only a schema derived on a class's behalf is held for it.
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
        Schematic::schemaOf(Forgetful::class);
        self::assertSame(3, Forgetful::$calls);
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
        self::assertEquals(Slug::fromString('hello-world'), Schematic::instantiate(Slug::class, 'hello-world')->valueOrThrow());
    }

    /**
     * It validates like every other path — reaching a private constructor is never a bypass of the schema.
     */
    public function testReachingAPrivateConstructorStillValidates(): void
    {
        $this->expectException(InstantiationFailed::class);
        Schematic::instantiate(Slug::class, 'Hello World')->valueOrThrow();
    }

    public function testAShapeReachesAPrivateConstructorByNamedArguments(): void
    {
        $hidden = Schematic::instantiate(Hidden::class, 'secret')->valueOrThrow();

        self::assertSame('secret', $hidden->reveal());
    }

    /**
     * The engine never calls a `from*()` named constructor. One that calls back into the facade — the documented
     * shape — would otherwise validate the same value twice per level of nesting, and recurse without end.
     */
    public function testTheEngineNeverCallsANamedConstructor(): void
    {
        $trapped = Schematic::instantiate(Trapped::class, 'quiet')->valueOrThrow();

        self::assertSame('quiet', $trapped->value);
    }

    /**
     * The invariant holds however the value was built, so the named constructor validates too — that is the whole
     * reason instantiation goes through the schema rather than around it.
     */
    public function testANamedConstructorEnforcesTheClassSchema(): void
    {
        $this->expectException(InstantiationFailed::class);
        $this->expectExceptionMessageMatches('/does not match the pattern/');
        Slug::fromString('Hello World');
    }

    public function testTheIssuesSurviveOnTheException(): void
    {
        try {
            Slug::fromString('');
            self::fail('expected the construction to fail');
        } catch (InstantiationFailed $exception) {
            self::assertSame([IssueCode::TooShort->value], $exception->issues->codes());
        }
    }

    public function testAClassWithAPublicConstructorIsBuiltDirectly(): void
    {
        $author = Schematic::instantiate(Author::class, 'Ada')->valueOrThrow();

        self::assertInstanceOf(Author::class, $author);
        self::assertSame('Ada', $author->name);
    }

    /**
     * No named constructor either: nothing about the class has to be arranged for the engine beyond the schema
     * it owns.
     */
    public function testAPrivateConstructorWithoutANamedConstructorIsStillBuilt(): void
    {
        $reclusive = Schematic::instantiate(Reclusive::class, 'nope')->valueOrThrow();

        self::assertSame('nope', $reclusive->value);
    }

    public function testAWholeShapeIsBuiltFromAPayload(): void
    {
        $result = Schematic::instantiate(Post::class, $this->payload());

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
        $post = Schematic::instantiate(Post::class, $this->payload())->value();

        self::assertInstanceOf(Post::class, $post);
        self::assertEquals(Slug::fromString('hello-world'), $post->slug);
    }

    public function testAnOptionalPropertyMayBeOmittedEntirely(): void
    {
        $payload = $this->payload();
        unset($payload['subtitle']);

        $post = Schematic::instantiate(Post::class, $payload)->value();

        self::assertInstanceOf(Post::class, $post);
        self::assertNull($post->subtitle);
    }

    /**
     * The point of validating the whole payload first: a bad request reports everything wrong with it at once,
     * each issue located where it belongs.
     */
    public function testEveryIssueInAPayloadIsReportedAtItsOwnPath(): void
    {
        $result = Schematic::instantiate(Post::class, ['slug' => 'Hello World', 'author' => 'Ada', 'status' => 'nope', 'tags' => []]);

        self::assertFalse($result->success);
        self::assertSame(['slug', 'status'], array_map(static fn($issue): string => $issue->pathAsString(), $result->issues->toArray()));
    }

    public function testInstantiateReportsIssuesInsteadOfThrowing(): void
    {
        $result = Schematic::instantiate(Slug::class, 'Hello World');

        self::assertFalse($result->success);
        self::assertSame([IssueCode::InvalidPattern->value], $result->issues->codes());
    }

    public function testABackedEnumIsResolvedByItsValue(): void
    {
        self::assertSame(Rating::high, Schematic::instantiate(Rating::class, 5)->valueOrThrow());
    }

    public function testAPureEnumIsResolvedByItsCaseName(): void
    {
        self::assertSame(Channel::phone, Schematic::instantiate(Channel::class, 'phone')->valueOrThrow());
    }

    public function testACollectionIsBuiltFromAList(): void
    {
        $tags = Schematic::instantiate(Tags::class, ['php', 'json'])->valueOrThrow();

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
        self::assertSame(Rating::high, Schematic::instantiate(Rating::class, 5)->valueOrThrow());
        self::assertFalse(Schematic::instantiate(Rating::class, '5')->success);
    }

    /**
     * One input shape, so that a payload that validates is one that builds: JSON decoded to associative arrays.
     */
    public function testAStdClassPayloadIsReportedRatherThanUnwrapped(): void
    {
        $result = Schematic::instantiate(Post::class, (object) $this->payload());

        self::assertFalse($result->success);
        self::assertSame([IssueCode::InvalidType->value], $result->issues->codes());
    }
}
