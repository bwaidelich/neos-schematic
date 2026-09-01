<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Serialization;

use Neos\JsonSchema\ArraySchema;
use Neos\Schematic\Schematic;
use Neos\Schematic\Serialization\Serializer;
use Neos\Schematic\Tests\Fixtures\Channel;
use Neos\Schematic\Tests\Fixtures\Hidden;
use Neos\Schematic\Tests\Fixtures\OpaqueTags;
use Neos\Schematic\Tests\Fixtures\Post;
use Neos\Schematic\Tests\Fixtures\Rating;
use Neos\Schematic\Tests\Fixtures\Status;
use Neos\Schematic\Tests\Fixtures\Tag;
use Neos\Schematic\Tests\Fixtures\TagList;
use Neos\Schematic\Tests\Fixtures\Tags;
use Neos\Schematic\UnextractableValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The way back out: public properties only, driven by the same class shape that built the value.
 */
#[CoversClass(Serializer::class)]
#[CoversClass(UnextractableValue::class)]
final class SerializerTest extends TestCase
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
            'subtitle' => 'a first post',
        ];
    }

    public function testAScalarValueObjectIsItsValue(): void
    {
        self::assertSame('php', Schematic::serialize(Schematic::schemaFor(Tag::class), new Tag('php')));
    }

    public function testABackedEnumIsItsBackingValue(): void
    {
        self::assertSame('draft', Schematic::serialize(Schematic::schemaFor(Status::class), Status::draft));
        self::assertSame(5, Schematic::serialize(Schematic::schemaFor(Rating::class), Rating::high));
    }

    public function testAPureEnumIsItsCaseName(): void
    {
        self::assertSame('phone', Schematic::serialize(Schematic::schemaFor(Channel::class), Channel::phone));
    }

    public function testACollectionIsItsItems(): void
    {
        $tags = Schematic::instanciate(Tags::class, ['php', 'json'])->valueOrThrow();

        self::assertSame(['php', 'json'], Schematic::serialize(Schematic::schemaFor(Tags::class), $tags));
    }

    /**
     * A collection answering "what is in me" itself is read through that answer rather than through a property.
     */
    public function testATraversableCollectionIsIterated(): void
    {
        self::assertSame(['php'], Schematic::serialize(Schematic::schemaFor(TagList::class), new TagList(new Tag('php'))));
    }

    /**
     * The round trip that matters: what goes out is exactly what came in, so the emitted body still satisfies the
     * schema the document published.
     */
    public function testAShapeRoundTripsThroughItsPrimitives(): void
    {
        $post = Schematic::instanciate(Post::class, $this->payload())->value();

        self::assertSame($this->payload(), Schematic::serialize(Schematic::schemaFor(Post::class), $post));
    }

    public function testTheTraitEmitsTheSameThingAsTheFacade(): void
    {
        $post = Schematic::instanciate(Post::class, $this->payload())->value();
        self::assertInstanceOf(Post::class, $post);

        self::assertSame(json_encode(Schematic::serialize(Schematic::schemaFor(Post::class), $post)), json_encode($post));
    }

    public function testANullPropertyStaysNull(): void
    {
        $payload = ['slug' => 'hello-world', 'author' => 'Ada', 'status' => 'draft', 'tags' => [], 'subtitle' => null];
        $post = Schematic::instanciate(Post::class, $payload)->value();

        self::assertSame($payload, Schematic::serialize(Schematic::schemaFor(Post::class), $post));
    }

    /**
     * The boundary a class drew going in is honoured going out: private state is not reflected past, it is
     * reported — the class answers by exposing it, or by writing its own `jsonSerialize()`.
     */
    public function testAValueObjectHidingItsStateFailsLoudly(): void
    {
        $hidden = Hidden::fromString('secret');

        $this->expectException(UnextractableValue::class);
        $this->expectExceptionMessageMatches('/public property "\$secret"/');
        // the result is never produced: the call throws before it can return one
        $unreached = Schematic::serialize(Schematic::schemaFor(Hidden::class), $hidden);
    }

    public function testACollectionHidingItsItemsFailsLoudly(): void
    {
        $tags = new OpaqueTags(new Tag('php'));

        $this->expectException(UnextractableValue::class);
        $this->expectExceptionMessageMatches('/IteratorAggregate/');
        // the result is never produced: the call throws before it can return one
        $unreached = Schematic::serialize(Schematic::schemaFor(OpaqueTags::class), $tags);
    }

    public function testPrimitivesPassThrough(): void
    {
        self::assertSame(['a', 1, true, null], Schematic::serialize(ArraySchema::create(), ['a', 1, true, null]));
    }
}
