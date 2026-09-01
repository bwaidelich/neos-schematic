<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Serialization;

use Neos\JsonSchema\AnySchema;
use Neos\JsonSchema\OneOfSchema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;
use Neos\Schematic\Serialization\Serializer;
use Neos\Schematic\Tests\Fixtures\Acknowledgement;
use Neos\Schematic\Tests\Fixtures\Either;
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
     * The property that was missing while serialization took no schema: `instantiate()` followed by `serialize()` gives
     * back what went in, empty map and empty list included.
     */
    public function testInstantiatingAndSerializingIsTheIdentity(): void
    {
        $payload = ['entries' => [], 'names' => []];

        $registry = Schematic::instantiate(Registry::class, $payload)->value();
        $serialized = Schematic::serialize($registry);

        self::assertSame('{"entries":{},"names":[]}', json_encode($serialized));
    }

    public function testAFilledMapKeepsItsMembers(): void
    {
        $payload = ['entries' => ['a' => 1, 'b' => 2], 'names' => ['x']];

        $registry = Schematic::instantiate(Registry::class, $payload)->value();

        self::assertSame($payload, Schematic::serialize($registry));
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
            Schematic::serialize(new Acknowledgement()),
        );
        self::assertSame('a-slug', Schematic::serialize(Slug::fromString('a-slug')));
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
            Serializer::serialize(new Registry(['a' => 1], ['x']), $union),
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

        self::assertSame(['acknowledged' => true], Serializer::serialize(new Acknowledgement(), $union));
    }

    /**
     * Key order is not something JSON defines — an object is an unordered set of members — so the schema is not
     * asked about it and nothing is reordered to match: members go out in the order extraction found them, which
     * is the constructor's, whatever order the schema happens to name them in.
     */
    public function testKeyOrderIsTheConstructorsAndIsNotAdjustedToTheSchema(): void
    {
        self::assertSame(['first' => 1, 'second' => 2], Schematic::serialize(new Reversed(1, 2)));
    }

    /**
     * A schema that makes no claim at all is not a disagreement: there is nothing for the class to overrule, so
     * the class answers. This is what a free-form member holding an object relies on.
     */
    public function testAnUnconstrainedSchemaLeavesTheReadingToTheClass(): void
    {
        self::assertSame('a-slug', Serializer::serialize(Slug::fromString('a-slug'), AnySchema::create()));
    }

    /**
     * A union member is read under the branch the value *is*, which is the only thing that can say so — and the
     * branch is what carries the constraints, so the empty map inside it still goes out as `{}` rather than `[]`.
     * The union itself has no properties to read anything from.
     */
    public function testAUnionMemberIsReadUnderTheBranchTheValueIs(): void
    {
        $either = new Either(new Registry([], []));

        self::assertSame('{"held":{"entries":{},"names":[]}}', json_encode(Schematic::serialize($either)));
    }

    /**
     * And the way in picks the same branch, from the primitives: the published `anyOf` says which shapes may
     * arrive, and the first branch that accepts a payload is the one it gets built as.
     */
    public function testAUnionMemberIsBuiltAsTheBranchItsPrimitivesMatch(): void
    {
        $registry = Schematic::instantiate(Either::class, ['held' => ['entries' => [], 'names' => []]])->value();
        $slug = Schematic::instantiate(Either::class, ['held' => 'a-slug'])->value();

        self::assertInstanceOf(Either::class, $registry);
        self::assertInstanceOf(Either::class, $slug);
        self::assertInstanceOf(Registry::class, $registry->held);
        self::assertInstanceOf(Slug::class, $slug->held);
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
        self::assertNull(Serializer::serialize(Slug::fromString('a-slug'), $union));
    }
}
