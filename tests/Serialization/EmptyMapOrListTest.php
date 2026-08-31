<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Serialization;

use Neos\JsonSchema\AllOfSchema;
use Neos\JsonSchema\ArraySchema;
use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\OneOfSchema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ArrayItems;
use Neos\JsonSchema\Support\ObjectProperties;
use Neos\Schematic\Serialization\Serializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The one question neither a PHP value nor a PHP class can answer about itself: which of JSON's two structures an
 * empty `array` was. The schema answers it, and that is *all* it is asked out here — key order is nothing JSON
 * defines, and every key the class emits goes out whether the schema names it or not.
 */
#[CoversClass(Serializer::class)]
final class EmptyMapOrListTest extends TestCase
{
    private function freeFormMap(): ObjectSchema
    {
        return ObjectSchema::create(additionalProperties: true);
    }

    public function testAnEmptyArrayUnderAnObjectSchemaIsAMap(): void
    {
        self::assertSame('{}', json_encode(Serializer::read([], $this->freeFormMap())));
    }

    /**
     * The direction that must *not* change: an empty list is still a list.
     */
    public function testAnEmptyArrayUnderAnArraySchemaStaysAList(): void
    {
        self::assertSame('[]', json_encode(Serializer::read([], ArraySchema::create(items: StringSchema::create()))));
    }

    public function testAnEmptyArrayNothingDescribesIsLeftAsItIs(): void
    {
        self::assertSame('[]', json_encode(Serializer::read([])));
        self::assertSame('[]', json_encode(Serializer::read([], AllOfSchema::create($this->freeFormMap()))));
    }

    public function testAFilledMapKeepsItsMembersInTheOrderTheValueHasThem(): void
    {
        $schema = ObjectSchema::create(
            properties: ObjectProperties::create(second: IntegerSchema::create(), first: IntegerSchema::create()),
        );

        self::assertSame(['first' => 1, 'second' => 2], Serializer::read(['first' => 1, 'second' => 2], $schema));
    }

    /**
     * A key the schema does not name is kept, even where it forbids one: dropping it would hide a disagreement
     * rather than let it be seen. (A *class* whose members disagree with its schema does not get this far — the
     * serializer raises that while reading the instance.)
     */
    public function testUndeclaredKeysAreKept(): void
    {
        $schema = ObjectSchema::create(
            properties: ObjectProperties::create(declared: StringSchema::create()),
            additionalProperties: false,
        );

        self::assertSame(['extra' => 'x', 'declared' => 'y'], Serializer::read(['extra' => 'x', 'declared' => 'y'], $schema));
    }

    /**
     * Only members the schema *names* are descended into: the values of a free-form map are described by nothing,
     * so an empty array in there is left as it is rather than guessed at.
     */
    public function testMembersOfAFreeFormMapAreNotDecided(): void
    {
        self::assertSame('{"a":[],"b":["x"]}', json_encode(Serializer::read(['a' => [], 'b' => ['x']], $this->freeFormMap())));
    }

    public function testANamedMemberIsDecidedByItsOwnSchema(): void
    {
        $schema = ObjectSchema::create(
            properties: ObjectProperties::create(map: $this->freeFormMap(), list: ArraySchema::create(items: StringSchema::create())),
        );

        self::assertSame('{"map":{},"list":[]}', json_encode(Serializer::read(['map' => [], 'list' => []], $schema)));
    }

    public function testItemsOfAListAreDecidedToo(): void
    {
        $schema = ArraySchema::create(items: $this->freeFormMap());

        self::assertSame('[{},{"a":1}]', json_encode(Serializer::read([[], ['a' => 1]], $schema)));
    }

    public function testPrefixItemsDescribeTheirPositions(): void
    {
        $schema = ArraySchema::create(
            prefixItems: ArrayItems::create($this->freeFormMap()),
            items: ArraySchema::create(items: StringSchema::create()),
        );

        self::assertSame('[{},[]]', json_encode(Serializer::read([[], []], $schema)));
    }

    /**
     * A union is read under the branch the value matches — which is what tells an empty object member from an
     * empty list member when the document says it may be either.
     */
    public function testAUnionIsReadUnderTheBranchTheValueMatches(): void
    {
        $schema = OneOfSchema::create(
            ObjectSchema::create(properties: ObjectProperties::create(map: $this->freeFormMap()), required: ['map']),
            ObjectSchema::create(properties: ObjectProperties::create(list: ArraySchema::create(items: StringSchema::create())), required: ['list']),
        );

        self::assertSame('{"map":{}}', json_encode(Serializer::read(['map' => []], $schema)));
        self::assertSame('{"list":[]}', json_encode(Serializer::read(['list' => []], $schema)));
    }
}
