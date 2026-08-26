<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes;

use Neos\Schematic\Attributes\ReflectionMiddleware;
use Neos\Schematic\Generation\SchemaFactory;
use Neos\Schematic\Generation\UnsupportedTypeException;
use Neos\Schematic\Instantiation\Mapper;
use Neos\Schematic\Schema\Kind;
use Neos\Schematic\Tests\Attributes\Fixtures\ContactAge;
use Neos\Schematic\Tests\Attributes\Fixtures\ContactName;
use Neos\Schematic\Tests\Attributes\Fixtures\NullableContact;
use Neos\Schematic\Tests\Attributes\Fixtures\UnsupportedMixed;
use PHPUnit\Framework\TestCase;

final class NullableReflectionTest extends TestCase
{
    private SchemaFactory $schemaFactory;
    private Mapper $mapper;

    protected function setUp(): void
    {
        $this->schemaFactory = new SchemaFactory(new ReflectionMiddleware());
        $this->mapper = new Mapper();
    }

    public function testNullableParametersProduceNullableNodes(): void
    {
        $schema = $this->schemaFactory->for(NullableContact::class);

        self::assertFalse($schema->properties['name']->nullable);
        self::assertTrue($schema->properties['nickname']->nullable);
        self::assertTrue($schema->properties['age']->nullable);
        self::assertTrue($schema->properties['note']->nullable);
    }

    public function testNullableNodesKeepTheirKindAndTarget(): void
    {
        $schema = $this->schemaFactory->for(NullableContact::class);

        self::assertSame(Kind::Scalar, $schema->properties['nickname']->kind);
        self::assertSame(ContactName::class, $schema->properties['nickname']->target);
        self::assertSame(ContactAge::class, $schema->properties['age']->target);
    }

    /**
     * Nullability and optionality are independent: `ContactName|null $nickname` has no default, so it stays
     * *required* — the caller must supply the key, and `null` is an accepted value for it.
     */
    public function testNullabilityDoesNotImplyOptionality(): void
    {
        $decoded = json_decode(
            (string) json_encode($this->schemaFactory->for(NullableContact::class)->toJsonSchema()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decoded);
        self::assertSame(['name', 'nickname'], $decoded['required']);
    }

    public function testNullableParameterRendersAsAnyOfWithNullBranch(): void
    {
        $decoded = json_decode(
            (string) json_encode($this->schemaFactory->for(NullableContact::class)->toJsonSchema()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decoded);
        self::assertIsArray($decoded['properties']);
        $nickname = $decoded['properties']['nickname'];
        self::assertIsArray($nickname);
        self::assertSame(
            [['type' => 'string', 'description' => 'The full name of a contact', 'minLength' => 1, 'maxLength' => 200], ['type' => 'null']],
            $nickname['anyOf'],
        );
    }

    public function testMapsExplicitNullsToNull(): void
    {
        $result = $this->mapper->map(
            $this->schemaFactory->for(NullableContact::class),
            ['name' => 'John Doe', 'nickname' => null],
        );

        self::assertTrue($result->success);
        $contact = $result->value();
        self::assertInstanceOf(NullableContact::class, $contact);
        self::assertSame('John Doe', $contact->name->value);
        self::assertNull($contact->nickname);
        self::assertNull($contact->age);
        self::assertNull($contact->note);
    }

    public function testMapsNonNullValuesIntoValueObjects(): void
    {
        $result = $this->mapper->map(
            $this->schemaFactory->for(NullableContact::class),
            ['name' => 'John Doe', 'nickname' => 'Johnny', 'age' => 45, 'note' => 'met at a conference'],
        );

        self::assertTrue($result->success);
        $contact = $result->value();
        self::assertInstanceOf(NullableContact::class, $contact);
        self::assertInstanceOf(ContactName::class, $contact->nickname);
        self::assertSame('Johnny', $contact->nickname->value);
        self::assertInstanceOf(ContactAge::class, $contact->age);
        self::assertSame(45, $contact->age->value);
        self::assertSame('met at a conference', $contact->note);
    }

    public function testStillEnforcesConstraintsOnTheNonNullBranch(): void
    {
        $result = $this->mapper->map(
            $this->schemaFactory->for(NullableContact::class),
            ['name' => 'John Doe', 'nickname' => ''],
        );

        self::assertFalse($result->success);
        $issues = iterator_to_array($result->issues);
        self::assertCount(1, $issues);
        self::assertSame('too_short', $issues[0]->code);
        self::assertSame(['nickname'], $issues[0]->path);
    }

    public function testStillRejectsNullForANonNullableParameter(): void
    {
        $result = $this->mapper->map(
            $this->schemaFactory->for(NullableContact::class),
            ['name' => null, 'nickname' => null],
        );

        self::assertFalse($result->success);
        $issues = iterator_to_array($result->issues);
        self::assertSame(['name'], $issues[0]->path);
    }

    public function testMixedRemainsUnsupported(): void
    {
        $this->expectException(UnsupportedTypeException::class);
        $this->schemaFactory->for(UnsupportedMixed::class);
    }
}
