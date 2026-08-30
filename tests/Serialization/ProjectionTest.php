<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Serialization;

use Neos\JsonSchema\AllOfSchema;
use Neos\JsonSchema\ArraySchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\OneOfSchema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;
use Neos\Schematic\Schematic;
use Neos\Schematic\Serialization\Projection;
use Neos\Schematic\Tests\Fixtures\Registry;
use Neos\Schematic\Tests\Fixtures\Reversed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What the class cannot say and the schema can: which of JSON's two structures a PHP `array` was.
 */
#[CoversClass(Projection::class)]
final class ProjectionTest extends TestCase
{
    /**
     * The property that was missing: `map()` followed by `serialize()` gives back what went in, empty map and
     * empty list included.
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
     * The regression guard for the fix itself: an empty *list* is still a list.
     */
    public function testAnEmptyArrayUnderAnArraySchemaStaysAnArray(): void
    {
        self::assertSame([], Schematic::serialize(ArraySchema::create(items: StringSchema::create()), []));
    }

    public function testAnEmptyArrayUnderAnObjectSchemaBecomesAnObject(): void
    {
        $serialized = Schematic::serialize(ObjectSchema::create(additionalProperties: true), []);

        self::assertInstanceOf(\stdClass::class, $serialized);
        self::assertSame('{}', json_encode($serialized));
    }

    /**
     * Descending only into members the schema *names* stops at a free-form map, whose values are described by
     * nothing: an empty array in there stays what the class held, because nothing says otherwise.
     */
    public function testAMapWithoutPropertiesIsLeftAsItIs(): void
    {
        $value = ['a' => [], 'b' => ['x']];

        self::assertSame($value, Schematic::serialize(ObjectSchema::create(additionalProperties: true), $value));
    }

    public function testTheSchemaDecidesTheKeyOrder(): void
    {
        $serialized = Schematic::serialize(Reversed::schema(), new Reversed(1, 2));

        self::assertSame(['second' => 2, 'first' => 1], $serialized);
    }

    /**
     * A key the schema does not publish is not dropped: the class emitting something its document does not
     * describe is a disagreement to report, not one to hide.
     */
    public function testUndeclaredKeysFollowTheDeclaredOnes(): void
    {
        $schema = ObjectSchema::create(properties: ObjectProperties::create(second: StringSchema::create()));

        $serialized = Schematic::serialize($schema, ['extra' => 'x', 'second' => 'y']);

        self::assertSame(['second' => 'y', 'extra' => 'x'], $serialized);
    }

    /**
     * A union is shaped by the branch the value actually matches, so a member is an object or an array depending
     * on which of the two the value turned out to be.
     */
    public function testAUnionIsShapedByTheBranchTheValueMatches(): void
    {
        $schema = OneOfSchema::create(
            ObjectSchema::create(
                properties: ObjectProperties::create(map: ObjectSchema::create(additionalProperties: true)),
                required: ['map'],
            ),
            ObjectSchema::create(
                properties: ObjectProperties::create(list: ArraySchema::create(items: StringSchema::create())),
                required: ['list'],
            ),
        );

        self::assertSame('{"map":{}}', json_encode(Schematic::serialize($schema, ['map' => []])));
        self::assertSame('{"list":[]}', json_encode(Schematic::serialize($schema, ['list' => []])));
    }

    /**
     * Schema types with no single structural reading are handed back untouched — the same answer the Validator
     * gives inbound, because guessing a shape is what loses data.
     */
    public function testASchemaWithNoStructuralReadingChangesNothing(): void
    {
        $schema = AllOfSchema::create(ObjectSchema::create(additionalProperties: true));

        self::assertSame([], Schematic::serialize($schema, []));
    }

    /**
     * Nested items are descended into, so an empty map inside a list is still a map.
     */
    public function testItemsOfAListAreShapedToo(): void
    {
        $schema = ArraySchema::create(items: ObjectSchema::create(additionalProperties: true));

        self::assertSame('[{},{"a":1}]', json_encode(Schematic::serialize($schema, [[], ['a' => 1]])));
    }
}
