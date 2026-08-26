<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes;

use Neos\JsonSchema\Validation\IssueCode;
use Neos\Schematic\Attributes\ReflectionMiddleware;
use Neos\Schematic\Coercion\InvalidValue;
use Neos\Schematic\Generation\SchemaFactory;
use Neos\Schematic\Instantiation\Mapper;
use Neos\Schematic\Schematic;
use Neos\Schematic\Tests\Attributes\Fixtures\Contact;
use Neos\Schematic\Tests\Attributes\Fixtures\ContactName;
use Neos\Schematic\Tests\Attributes\Fixtures\ContactNames;
use Neos\Schematic\Tests\Attributes\Fixtures\DateRange;
use PHPUnit\Framework\TestCase;

final class ReflectionMiddlewareTest extends TestCase
{
    private SchemaFactory $schemaFactory;
    private Mapper $mapper;

    protected function setUp(): void
    {
        $this->schemaFactory = new SchemaFactory(new ReflectionMiddleware());
        $this->mapper = new Mapper();
    }

    public function testWiresIntoTheSchematicEntryPoint(): void
    {
        $schematic = Schematic::create(new ReflectionMiddleware());

        $result = $schematic->map(Contact::class, ['name' => 'John Doe', 'age' => 45]);

        self::assertTrue($result->success);
        $contact = $result->value();
        self::assertInstanceOf(Contact::class, $contact);
        self::assertSame('John Doe', $contact->name->value);
    }

    public function testMapsScalarValueObjectWithPrivateConstructor(): void
    {
        $result = $this->mapper->map($this->schemaFactory->for(ContactName::class), 'John Doe');

        self::assertTrue($result->success);
        $name = $result->value();
        self::assertInstanceOf(ContactName::class, $name);
        self::assertSame('John Doe', $name->value);
    }

    public function testEnforcesAttributeConstraintsDuringCoercion(): void
    {
        $result = $this->mapper->map($this->schemaFactory->for(ContactName::class), '');

        self::assertFalse($result->success);
        self::assertSame(IssueCode::TooShort->value, $result->issues->toArray()[0]->code);
    }

    public function testMapsShapeWithNestedValueObjects(): void
    {
        $result = $this->mapper->map($this->schemaFactory->for(Contact::class), ['name' => 'John Doe', 'age' => 45]);

        self::assertTrue($result->success);
        $contact = $result->value();
        self::assertInstanceOf(Contact::class, $contact);
        self::assertSame('John Doe', $contact->name->value);
        self::assertSame(45, $contact->age->value);
    }

    public function testAggregatesNestedConstraintViolations(): void
    {
        $result = $this->mapper->map($this->schemaFactory->for(Contact::class), ['name' => '', 'age' => 500]);

        self::assertFalse($result->success);
        self::assertCount(2, $result->issues);
    }

    public function testMapsListValueObject(): void
    {
        $result = $this->mapper->map($this->schemaFactory->for(ContactNames::class), ['Ada', 'Grace']);

        self::assertTrue($result->success);
        $names = $result->value();
        self::assertInstanceOf(ContactNames::class, $names);
        self::assertCount(2, $names->names);
        self::assertSame('Ada', $names->names[0]->value);
        self::assertSame('Grace', $names->names[1]->value);
    }

    public function testConstructorInvariantBecomesIssue(): void
    {
        $result = $this->mapper->map($this->schemaFactory->for(DateRange::class), ['start' => 10, 'end' => 5]);

        self::assertFalse($result->success);
        self::assertSame(InvalidValue::ISSUE_CODE, $result->issues->toArray()[0]->code);
    }

    public function testRendersJsonSchemaFromAttributes(): void
    {
        $encoded = json_encode($this->schemaFactory->for(Contact::class)->toJsonSchema());
        self::assertIsString($encoded);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('object', $decoded['type']);
        self::assertSame('A contact', $decoded['description']);
        self::assertSame(['name', 'age'], $decoded['required']);
        self::assertIsArray($decoded['properties']);
        self::assertIsArray($decoded['properties']['name']);
        self::assertSame('string', $decoded['properties']['name']['type']);
        self::assertSame(1, $decoded['properties']['name']['minLength']);
        self::assertSame('The full name of a contact', $decoded['properties']['name']['description']);
        self::assertIsArray($decoded['properties']['age']);
        self::assertSame('integer', $decoded['properties']['age']['type']);
        self::assertSame(0, $decoded['properties']['age']['minimum']);
    }
}
