<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Schema;

use Neos\JsonSchema\AnyOfSchema as JsonAnyOfSchema;
use Neos\JsonSchema\ObjectSchema as JsonObjectSchema;
use Neos\JsonSchema\StringSchema as JsonStringSchema;
use Neos\Schematic\Instantiation\Mapper;
use Neos\Schematic\Schema\Kind;
use Neos\Schematic\Schema\Schema;
use Neos\Schematic\Tests\Fixtures\Contact;
use Neos\Schematic\Tests\Fixtures\ContactName;
use Neos\Schematic\Tests\Fixtures\Fixtures;
use Neos\Schematic\Tests\Fixtures\NullableContact;
use PHPUnit\Framework\TestCase;

final class NullableTest extends TestCase
{
    public function testAsNullableKeepsKindAndWrappedSchema(): void
    {
        $schema = Fixtures::contactName()->asNullable();

        self::assertTrue($schema->nullable);
        self::assertSame(Kind::Scalar, $schema->kind);
        self::assertInstanceOf(JsonStringSchema::class, $schema->jsonSchema);
        self::assertSame(ContactName::class, $schema->target);
    }

    public function testAsNullableIsIdempotent(): void
    {
        $schema = Fixtures::contactName()->asNullable();

        self::assertSame($schema, $schema->asNullable());
    }

    public function testNamedConstructorsProduceNonNullableNodes(): void
    {
        self::assertFalse(Fixtures::contactName()->nullable);
        self::assertFalse(Fixtures::contact()->nullable);
        self::assertFalse(Fixtures::contactNames()->nullable);
    }

    public function testRendersAsAnyOfWithNullBranch(): void
    {
        $json = Fixtures::contactName()->asNullable()->toJsonSchema();

        self::assertInstanceOf(JsonAnyOfSchema::class, $json);
        self::assertSame(
            ['anyOf' => [['type' => 'string', 'minLength' => 1, 'maxLength' => 200], ['type' => 'null']]],
            json_decode((string) json_encode($json), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The regression this whole design guards against: nullability must not be modelled by replacing the node's
     * wrapped `jsonSchema` with an `AnyOf`, because the `instanceof JsonObjectSchema` guard in
     * `Schema::objectToJsonSchema()` would then fail and drop every property without any error.
     */
    public function testNullableObjectStillRendersItsProperties(): void
    {
        $json = Fixtures::contact()->asNullable()->toJsonSchema();

        self::assertInstanceOf(JsonAnyOfSchema::class, $json);
        $branches = iterator_to_array($json);
        self::assertInstanceOf(JsonObjectSchema::class, $branches[0]);
        self::assertSame(['name', 'age'], $branches[0]->properties?->names());
    }

    public function testNullableListStillRendersItsItems(): void
    {
        $decoded = json_decode(
            (string) json_encode(Fixtures::contactNames()->asNullable()->toJsonSchema()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decoded);
        self::assertIsArray($decoded['anyOf']);
        $arrayBranch = $decoded['anyOf'][0];
        self::assertIsArray($arrayBranch);
        self::assertSame('array', $arrayBranch['type']);
        self::assertIsArray($arrayBranch['items']);
        self::assertSame('string', $arrayBranch['items']['type']);
    }

    public function testNullIsAcceptedForANullableNode(): void
    {
        $result = (new Mapper())->map(Fixtures::contactName()->asNullable(), null);

        self::assertTrue($result->success);
        self::assertNull($result->value());
    }

    public function testNullIsRejectedForANonNullableNode(): void
    {
        $result = (new Mapper())->map(Fixtures::contactName(), null);

        self::assertFalse($result->success);
    }

    public function testANonNullValueStillCoercesAndInstantiates(): void
    {
        $result = (new Mapper())->map(Fixtures::contactName()->asNullable(), 'Alice');

        self::assertTrue($result->success);
        $name = $result->value();
        self::assertInstanceOf(ContactName::class, $name);
        self::assertSame('Alice', $name->value);
    }

    /**
     * The Instantiator has to short-circuit `null` *before* dispatching to the InstanceFactory — otherwise the
     * factory reaches `new ContactName(null)` and dies with a TypeError.
     */
    public function testNullSkipsTheInstanceFactoryEntirely(): void
    {
        $schema = Schema::scalar(JsonStringSchema::create(), ContactName::class, ExplodingFactory::class)->asNullable();

        $result = (new Mapper())->map($schema, null);

        self::assertTrue($result->success);
        self::assertNull($result->value());
    }

    public function testNullIsAcceptedForANullableObjectNode(): void
    {
        $result = (new Mapper())->map(Fixtures::contact()->asNullable(), null);

        self::assertTrue($result->success);
        self::assertNull($result->value());
    }

    public function testNullIsAcceptedForANullableListNode(): void
    {
        $result = (new Mapper())->map(Fixtures::contactNames()->asNullable(), null);

        self::assertTrue($result->success);
        self::assertNull($result->value());
    }

    public function testANullableObjectNodeStillBuildsFromAnArray(): void
    {
        $result = (new Mapper())->map(Fixtures::contact()->asNullable(), ['name' => 'Alice', 'age' => 30]);

        self::assertTrue($result->success);
        self::assertInstanceOf(Contact::class, $result->value());
    }

    public function testNullablePropertiesOmittedFromInputBecomeNull(): void
    {
        $result = (new Mapper())->map(Fixtures::nullableContact(), ['name' => 'Alice']);

        self::assertTrue($result->success);
        $contact = $result->value();
        self::assertInstanceOf(NullableContact::class, $contact);
        self::assertSame('Alice', $contact->name->value);
        self::assertNull($contact->nickname);
        self::assertNull($contact->note);
    }

    public function testExplicitNullsInInputBecomeNull(): void
    {
        $result = (new Mapper())->map(
            Fixtures::nullableContact(),
            ['name' => 'Alice', 'nickname' => null, 'note' => null],
        );

        self::assertTrue($result->success);
        $contact = $result->value();
        self::assertInstanceOf(NullableContact::class, $contact);
        self::assertNull($contact->nickname);
        self::assertNull($contact->note);
    }

    public function testNonNullNestedValuesAreStillBuilt(): void
    {
        $result = (new Mapper())->map(
            Fixtures::nullableContact(),
            ['name' => 'Alice', 'nickname' => 'Al', 'note' => 'a note'],
        );

        self::assertTrue($result->success);
        $contact = $result->value();
        self::assertInstanceOf(NullableContact::class, $contact);
        self::assertInstanceOf(ContactName::class, $contact->nickname);
        self::assertSame('Al', $contact->nickname->value);
        self::assertSame('a note', $contact->note);
    }

    /**
     * A nullable node is "this shape, or null" — not "anything". Constraint violations on the non-null branch
     * must still be reported, and with the *precise* issue rather than a generic "matches nothing".
     */
    public function testConstraintsOnTheNonNullBranchAreStillEnforced(): void
    {
        $result = (new Mapper())->map(Fixtures::nullableContact(), ['name' => 'Alice', 'nickname' => '']);

        self::assertFalse($result->success);
        $issues = iterator_to_array($result->issues);
        self::assertCount(1, $issues);
        self::assertSame('too_short', $issues[0]->code);
        self::assertSame(['nickname'], $issues[0]->path);
    }

    public function testWrongTypeOnANullablePropertyReportsTheTypeIssue(): void
    {
        $result = (new Mapper())->map(Fixtures::nullableContact(), ['name' => 'Alice', 'note' => 42]);

        self::assertFalse($result->success);
        $issues = iterator_to_array($result->issues);
        self::assertCount(1, $issues);
        self::assertSame('invalid_type', $issues[0]->code);
        self::assertSame(['note'], $issues[0]->path);
    }
}
