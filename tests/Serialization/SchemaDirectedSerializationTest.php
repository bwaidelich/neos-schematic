<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Serialization;

use Neos\JsonSchema\AnySchema;
use Neos\JsonSchema\OneOfSchema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;
use Neos\Schematic\Tests\Fixtures\Acknowledgement;
use Neos\Schematic\Tests\Fixtures\Registry;
use Neos\Schematic\Tests\Fixtures\Reversed;
use Neos\Schematic\Tests\Fixtures\Slug;
use Neos\Schematic\UnextractableValue;
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
     * A union is not a gap in what the schema knows. Its branches cannot be narrowed here — picking one means
     * validating the value against it, and the value is still an object at this point — but they do not have to
     * be: a `oneOf` of two object schemas is an object whichever branch it turns out to be, and that is the only
     * question being asked at this point.
     */
    public function testAUnionWhoseBranchesAgreeOnTheKindIsAnswered(): void
    {
        $union = OneOfSchema::create(Registry::schema(), Acknowledgement::schema());

        self::assertSame(
            ['entries' => ['a' => 1], 'names' => ['x']],
            Schematic::serialize($union, new Registry(['a' => 1], ['x'])),
        );
    }

    /**
     * And the envelope inside that union, which is the case that would go wrong if the union were answered by
     * the class: its constructor says "single value" and the branches all say "object". Which *branch* a value
     * turns out to be is still nobody's business here — that is settled after extraction, against primitives.
     */
    public function testAnEnvelopeInsideAUnionOfObjectsIsStillAnObject(): void
    {
        $union = OneOfSchema::create(Registry::schema(), Acknowledgement::schema());

        self::assertSame(['acknowledged' => true], Schematic::serialize($union, new Acknowledgement()));
    }

    /**
     * The document is what an emitted body is judged against, so where the two disagree the document's order is
     * the one that goes out — not the constructor's, which is what drives extraction.
     */
    public function testTheSchemaDecidesTheKeyOrderRatherThanTheConstructor(): void
    {
        self::assertSame(['second' => 2, 'first' => 1], Schematic::serialize(Reversed::schema(), new Reversed(1, 2)));
    }

    /**
     * A schema that makes no claim at all is not a disagreement: there is nothing for the class to overrule, so
     * the class answers. This is what a free-form member holding an object relies on.
     */
    public function testAnUnconstrainedSchemaLeavesTheReadingToTheClass(): void
    {
        self::assertSame('a-slug', Schematic::serialize(AnySchema::create(), Slug::fromString('a-slug')));
    }

    /**
     * But a schema whose branches disagree about what kind of thing they are is a *claim* that cannot be
     * reduced, and reading the class instead would silently pick the loser of a real disagreement. That is the
     * bug this whole mechanism exists to prevent, so it is refused rather than guessed.
     */
    public function testAUnionWhoseBranchesDisagreeIsRefusedRatherThanGuessed(): void
    {
        $union = OneOfSchema::create(StringSchema::create(), Registry::schema());

        $this->expectException(UnextractableValue::class);
        $this->expectExceptionMessageMatches('/no single structural reading/');
        self::assertNull(Schematic::serialize($union, Slug::fromString('a-slug')));
    }
}
