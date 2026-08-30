<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Serialization;

use Neos\Schematic\Schematic;
use Neos\Schematic\Tests\Fixtures\Acknowledgement;
use Neos\Schematic\Tests\Fixtures\Registry;
use Neos\Schematic\Tests\Fixtures\Reversed;
use Neos\Schematic\Tests\Fixtures\Slug;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What the class holds and what the document describes are not the same thing, and only the second knows whether
 * an empty PHP array was a map or a list. The walk itself belongs to `neos/jsonschema` and is pinned there; these
 * are the properties a *class* gets from it.
 */
#[CoversClass(Schematic::class)]
final class SchemaDirectedSerializationTest extends TestCase
{
    /**
     * The property that was missing while serialization took no schema: `map()` followed by `serialize()` gives
     * back what went in, empty map and empty list included.
     */
    public function testMappingAndSerializingIsTheIdentity(): void
    {
        $payload = ['entries' => new \stdClass(), 'names' => []];

        $registry = Schematic::map(Registry::class, $payload)->value();
        $serialized = Schematic::serialize(Registry::schema(), $registry);

        self::assertSame(json_encode($payload), json_encode($serialized));
        self::assertSame('{"entries":{},"names":[]}', json_encode($serialized));
    }

    public function testAFilledMapKeepsItsMembers(): void
    {
        $payload = ['entries' => ['a' => 1, 'b' => 2], 'names' => ['x']];

        $registry = Schematic::map(Registry::class, $payload)->value();

        self::assertSame($payload, Schematic::serialize(Registry::schema(), $registry));
    }

    /**
     * The other thing a class cannot say about itself: `Acknowledgement{bool $acknowledged}` and
     * `Slug{string $value}` have the same shape of constructor and opposite intent, so reading both off the
     * constructor makes both a single value and only the second one right. The schema says which is which -
     * `type: object` against `type: string` - and is consulted before the class shape for exactly this reason.
     */
    public function testTheSchemaTellsAnEnvelopeFromAValueObject(): void
    {
        self::assertSame(
            ['acknowledged' => true],
            Schematic::serialize(Acknowledgement::schema(), new Acknowledgement()),
        );
        self::assertSame('a-slug', Schematic::serialize(Slug::schema(), Slug::fromString('a-slug')));
    }

    /**
     * The document is what an emitted body is judged against, so where the two disagree the document's order is
     * the one that goes out — not the constructor's, which is what drives extraction.
     */
    public function testTheSchemaDecidesTheKeyOrderRatherThanTheConstructor(): void
    {
        self::assertSame(['second' => 2, 'first' => 1], Schematic::serialize(Reversed::schema(), new Reversed(1, 2)));
    }
}
