<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests;

use Neos\Schematic\Conformance;
use Neos\Schematic\Tests\Fixtures\Author;
use Neos\Schematic\Tests\Fixtures\Drift\EmittedOnly;
use Neos\Schematic\Tests\Fixtures\Drift\NullWidened;
use Neos\Schematic\Tests\Fixtures\Drift\OptionalRequired;
use Neos\Schematic\Tests\Fixtures\Drift\PublishedButUnbuilt;
use Neos\Schematic\Tests\Fixtures\Drift\StructureForAValue;
use Neos\Schematic\Tests\Fixtures\Post;
use Neos\Schematic\Tests\Fixtures\Rating;
use Neos\Schematic\Tests\Fixtures\Slug;
use Neos\Schematic\Tests\Fixtures\Status;
use Neos\Schematic\Tests\Fixtures\Tags;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A schema and the class that publishes it can disagree, and every way they can is invisible until it bites. This
 * is where a class asks.
 */
#[CoversClass(Conformance::class)]
final class ConformanceTest extends TestCase
{
    /**
     * A derived schema is *read off* the same shape the engine builds by, so there is nothing for it to disagree
     * with — whatever the nature.
     */
    public function testADerivedSchemaAgreesWithItsClassByConstruction(): void
    {
        self::assertSame([], Conformance::check(Post::class));
        self::assertSame([], Conformance::check(Tags::class));
        self::assertSame([], Conformance::check(Rating::class));
    }

    /**
     * And a hand-written one can agree too — this is not a rule against writing schemas by hand, it is how to know
     * that one still fits.
     */
    public function testAHandWrittenSchemaCanAgreeToo(): void
    {
        self::assertSame([], Conformance::check(Slug::class));
    }

    public function testASchemaAcceptingANullTheConstructorRefusesIsReported(): void
    {
        self::assertSame(
            ['$subtitle: the schema accepts null, the constructor does not'],
            Conformance::check(NullWidened::class),
        );
    }

    public function testAParameterWithoutADefaultThatTheSchemaLeavesOptionalIsReported(): void
    {
        self::assertSame(
            ['$author: the constructor has no default for it, but the schema does not require it'],
            Conformance::check(OptionalRequired::class),
        );
    }

    public function testASchemaDescribingAStructureForASingleValueIsReported(): void
    {
        self::assertSame(
            ['the class is a single value, but its schema describes a structure'],
            Conformance::check(StructureForAValue::class),
        );
    }

    public function testAPublishedPropertyNoConstructorNamesIsReported(): void
    {
        self::assertSame(
            ['"summary": the schema publishes it, the constructor names no such parameter'],
            Conformance::check(PublishedButUnbuilt::class),
        );
    }

    /**
     * A type nothing ever builds is not answering the constructor question at all — it is held to what it emits.
     */
    public function testAnOutputOnlyTypeIsJudgedByWhatItEmits(): void
    {
        self::assertSame(
            ['the class is a single value, but its schema describes a structure'],
            Conformance::check(EmittedOnly::class),
        );
        self::assertSame([], Conformance::checkSerialization(new EmittedOnly('nope')));
    }

    /**
     * The other half needs an instance, because it is about what the class *holds*: serialization publishes that
     * as it finds it, and a public constructor lets `new` produce a value validation never saw.
     */
    public function testAnInstanceIsJudgedByWhatItPutsOnTheWire(): void
    {
        $post = new Post(
            slug: Slug::fromString('hello-world'),
            author: new Author('Ada'),
            status: Status::draft,
            tags: new Tags(),
        );

        self::assertSame([], Conformance::checkSerialization($post));
    }
}
