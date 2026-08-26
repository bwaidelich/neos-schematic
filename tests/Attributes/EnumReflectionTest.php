<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes;

use Neos\JsonSchema\IntegerSchema as JsonIntegerSchema;
use Neos\JsonSchema\StringSchema as JsonStringSchema;
use Neos\JsonSchema\Validation\IssueCode;
use Neos\Schematic\Attributes\Instantiation\EnumFactory;
use Neos\Schematic\Attributes\ReflectionMiddleware;
use Neos\Schematic\Generation\SchemaFactory;
use Neos\Schematic\Generation\UnsupportedTypeException;
use Neos\Schematic\Instantiation\Mapper;
use Neos\Schematic\Schema\Kind;
use Neos\Schematic\Tests\Attributes\Fixtures\ClassifiedContact;
use Neos\Schematic\Tests\Attributes\Fixtures\ContactChannel;
use Neos\Schematic\Tests\Attributes\Fixtures\ContactPriority;
use Neos\Schematic\Tests\Attributes\Fixtures\ContactStatus;
use Neos\Schematic\Tests\Attributes\Fixtures\EmptyEnum;
use PHPUnit\Framework\TestCase;

final class EnumReflectionTest extends TestCase
{
    private SchemaFactory $schemaFactory;
    private Mapper $mapper;

    protected function setUp(): void
    {
        $this->schemaFactory = new SchemaFactory(new ReflectionMiddleware());
        $this->mapper = new Mapper();
    }

    public function testAStringBackedEnumBecomesAConstrainedStringScalar(): void
    {
        $schema = $this->schemaFactory->for(ContactStatus::class);

        self::assertSame(Kind::Scalar, $schema->kind);
        self::assertSame(ContactStatus::class, $schema->target);
        self::assertSame(EnumFactory::class, $schema->factory);
        self::assertInstanceOf(JsonStringSchema::class, $schema->jsonSchema);
        self::assertSame(['active', 'archived'], $schema->jsonSchema->enum);
    }

    public function testAnIntBackedEnumBecomesAConstrainedIntegerScalar(): void
    {
        $schema = $this->schemaFactory->for(ContactPriority::class);

        self::assertInstanceOf(JsonIntegerSchema::class, $schema->jsonSchema);
        self::assertSame([1, 10], $schema->jsonSchema->enum);
    }

    /**
     * A pure enum has no backing values, so its cases are identified by name.
     */
    public function testAPureEnumIsDescribedByItsCaseNames(): void
    {
        $schema = $this->schemaFactory->for(ContactChannel::class);

        self::assertInstanceOf(JsonStringSchema::class, $schema->jsonSchema);
        self::assertSame(['email', 'phone'], $schema->jsonSchema->enum);
    }

    public function testTheDescriptionAttributeIsReadOnEnums(): void
    {
        $schema = $this->schemaFactory->for(ContactStatus::class);

        self::assertInstanceOf(JsonStringSchema::class, $schema->jsonSchema);
        self::assertSame('Whether a contact is still reachable', $schema->jsonSchema->description);
    }

    public function testAnEnumWithoutCasesIsRejected(): void
    {
        $this->expectException(UnsupportedTypeException::class);
        $this->schemaFactory->for(EmptyEnum::class);
    }

    public function testMapsABackingValueToItsCase(): void
    {
        $result = $this->mapper->map($this->schemaFactory->for(ContactStatus::class), 'archived');

        self::assertTrue($result->success);
        self::assertSame(ContactStatus::archived, $result->value());
    }

    public function testMapsACaseNameToItsCaseForAPureEnum(): void
    {
        $result = $this->mapper->map($this->schemaFactory->for(ContactChannel::class), 'phone');

        self::assertTrue($result->success);
        self::assertSame(ContactChannel::phone, $result->value());
    }

    /**
     * The Coercer normalizes numeric strings for an integer schema, so a query-string `"10"` still resolves.
     */
    public function testMapsANumericStringToAnIntBackedCase(): void
    {
        $result = $this->mapper->map($this->schemaFactory->for(ContactPriority::class), '10');

        self::assertTrue($result->success);
        self::assertSame(ContactPriority::high, $result->value());
    }

    public function testRejectsAValueThatIsNotACase(): void
    {
        $result = $this->mapper->map($this->schemaFactory->for(ContactStatus::class), 'deleted');

        self::assertFalse($result->success);
        $issues = iterator_to_array($result->issues);
        self::assertSame(IssueCode::InvalidEnumValue->value, $issues[0]->code);
    }

    /**
     * A pure enum is identified by case *name*; the backing-value spelling of a *different* enum must not leak in.
     */
    public function testRejectsAValueOfTheWrongCase(): void
    {
        $result = $this->mapper->map($this->schemaFactory->for(ContactChannel::class), 'Email');

        self::assertFalse($result->success);
    }

    public function testRendersAsAJsonSchemaEnum(): void
    {
        $decoded = json_decode(
            (string) json_encode($this->schemaFactory->for(ContactStatus::class)->toJsonSchema()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            ['type' => 'string', 'description' => 'Whether a contact is still reachable', 'enum' => ['active', 'archived']],
            $decoded,
        );
    }

    public function testEnumsWorkAsPropertiesOfAShape(): void
    {
        $result = $this->mapper->map($this->schemaFactory->for(ClassifiedContact::class), [
            'name' => 'John Doe',
            'status' => 'active',
            'priority' => 1,
            'channel' => 'email',
        ]);

        self::assertTrue($result->success);
        $contact = $result->value();
        self::assertInstanceOf(ClassifiedContact::class, $contact);
        self::assertSame(ContactStatus::active, $contact->status);
        self::assertSame(ContactPriority::low, $contact->priority);
        self::assertSame(ContactChannel::email, $contact->channel);
    }

    public function testAnInvalidEnumPropertyIsReportedAtItsPath(): void
    {
        $result = $this->mapper->map($this->schemaFactory->for(ClassifiedContact::class), [
            'name' => 'John Doe',
            'status' => 'nope',
            'priority' => 1,
            'channel' => 'email',
        ]);

        self::assertFalse($result->success);
        $issues = iterator_to_array($result->issues);
        self::assertSame(['status'], $issues[0]->path);
        self::assertSame(IssueCode::InvalidEnumValue->value, $issues[0]->code);
    }

    /**
     * Nullable enums compose with the flag added in the previous change: the Instantiator short-circuits `null`
     * before EnumFactory ever runs.
     */
    public function testANullableEnumPropertyAcceptsNull(): void
    {
        $schema = $this->schemaFactory->for(ClassifiedContact::class);
        self::assertTrue($schema->properties['previousStatus']->nullable);

        $result = $this->mapper->map($schema, [
            'name' => 'John Doe',
            'status' => 'active',
            'priority' => 1,
            'channel' => 'email',
            'previousStatus' => null,
        ]);

        self::assertTrue($result->success);
        $contact = $result->value();
        self::assertInstanceOf(ClassifiedContact::class, $contact);
        self::assertNull($contact->previousStatus);
    }

    public function testANullableEnumPropertyStillResolvesARealCase(): void
    {
        $result = $this->mapper->map($this->schemaFactory->for(ClassifiedContact::class), [
            'name' => 'John Doe',
            'status' => 'active',
            'priority' => 1,
            'channel' => 'email',
            'previousStatus' => 'archived',
        ]);

        self::assertTrue($result->success);
        $contact = $result->value();
        self::assertInstanceOf(ClassifiedContact::class, $contact);
        self::assertSame(ContactStatus::archived, $contact->previousStatus);
    }
}
