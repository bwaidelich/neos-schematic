<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes;

use Neos\Schematic\Attributes\ReflectionMiddleware;
use Neos\Schematic\Attributes\Serialization\EnumExtractor;
use Neos\Schematic\Attributes\Serialization\ListExtractor;
use Neos\Schematic\Attributes\Serialization\ScalarExtractor;
use Neos\Schematic\Attributes\Serialization\ShapeExtractor;
use Neos\Schematic\Schematic;
use Neos\Schematic\Serialization\UnextractableValue;
use Neos\Schematic\Tests\Attributes\Fixtures\ClassifiedContact;
use Neos\Schematic\Tests\Attributes\Fixtures\Contact;
use Neos\Schematic\Tests\Attributes\Fixtures\ContactChannel;
use Neos\Schematic\Tests\Attributes\Fixtures\ContactName;
use Neos\Schematic\Tests\Attributes\Fixtures\ContactNames;
use Neos\Schematic\Tests\Attributes\Fixtures\ContactStatus;
use Neos\Schematic\Tests\Attributes\Fixtures\NullableContact;
use Neos\Schematic\Tests\Attributes\Fixtures\OpaqueList;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end serialization through the attribute batteries: `ReflectionMiddleware` stamps a ValueExtractor
 * alongside every InstanceFactory, so anything it can map, it can also read back.
 */
final class SerializationTest extends TestCase
{
    private Schematic $schematic;

    protected function setUp(): void
    {
        $this->schematic = Schematic::create(new ReflectionMiddleware());
    }

    public function testEveryKindGetsItsMatchingExtractorStamped(): void
    {
        self::assertSame(ScalarExtractor::class, $this->schematic->schemaFor(ContactName::class)->extractor);
        self::assertSame(ShapeExtractor::class, $this->schematic->schemaFor(Contact::class)->extractor);
        self::assertSame(ListExtractor::class, $this->schematic->schemaFor(ContactNames::class)->extractor);
        self::assertSame(EnumExtractor::class, $this->schematic->schemaFor(ContactStatus::class)->extractor);
    }

    public function testSerializesAScalarValueObjectToItsScalar(): void
    {
        $name = $this->schematic->map(ContactName::class, 'John Doe')->value();

        self::assertSame('John Doe', $this->schematic->serialize(ContactName::class, $name));
    }

    public function testSerializesAShapeToAnArray(): void
    {
        $input = ['name' => 'John Doe', 'age' => 45];
        $contact = $this->schematic->map(Contact::class, $input)->value();

        self::assertSame($input, $this->schematic->serialize(Contact::class, $contact));
    }

    public function testSerializesAListToItsItems(): void
    {
        $input = ['John Doe', 'Jane Doe'];
        $names = $this->schematic->map(ContactNames::class, $input)->value();

        self::assertSame($input, $this->schematic->serialize(ContactNames::class, $names));
    }

    public function testSerializesABackedEnumToItsBackingValue(): void
    {
        self::assertSame('archived', $this->schematic->serialize(ContactStatus::class, ContactStatus::archived));
    }

    public function testSerializesAPureEnumToItsCaseName(): void
    {
        self::assertSame('phone', $this->schematic->serialize(ContactChannel::class, ContactChannel::phone));
    }

    public function testSerializesANestedGraphWithEveryKind(): void
    {
        $input = [
            'name' => 'John Doe',
            'status' => 'active',
            'priority' => 10,
            'channel' => 'email',
            'previousStatus' => 'archived',
        ];
        $contact = $this->schematic->map(ClassifiedContact::class, $input)->value();

        self::assertSame($input, $this->schematic->serialize(ClassifiedContact::class, $contact));
    }

    public function testSerializesANullNullablePropertyAsNull(): void
    {
        $input = ['name' => 'John Doe', 'nickname' => null];
        $contact = $this->schematic->map(NullableContact::class, $input)->value();

        self::assertSame(
            ['name' => 'John Doe', 'nickname' => null, 'age' => null, 'note' => null],
            $this->schematic->serialize(NullableContact::class, $contact),
        );
    }

    /**
     * The schema advertises every property, so the emitted document must not be narrower than the published
     * contract, even for properties equal to their default.
     */
    public function testPropertiesEqualToTheirDefaultAreStillEmitted(): void
    {
        $contact = $this->schematic->map(NullableContact::class, ['name' => 'John Doe', 'nickname' => 'Johnny'])->value();

        $serialized = $this->schematic->serialize(NullableContact::class, $contact);

        self::assertIsArray($serialized);
        self::assertArrayHasKey('age', $serialized);
        self::assertArrayHasKey('note', $serialized);
    }

    /**
     * The result is `json_encode`-ready primitives — the point of the whole exercise.
     */
    public function testTheResultEncodesToJsonMatchingTheAdvertisedSchema(): void
    {
        $contact = $this->schematic->map(Contact::class, ['name' => 'John Doe', 'age' => 45])->value();

        $encoded = json_encode($this->schematic->serialize(Contact::class, $contact), JSON_THROW_ON_ERROR);

        self::assertSame('{"name":"John Doe","age":45}', $encoded);
        self::assertTrue($this->schematic->schemaFor(Contact::class)->toJsonSchema()->validate(
            json_decode($encoded, true, flags: JSON_THROW_ON_ERROR),
        )->valid);
    }

    /**
     * A list-based value object that is neither Traversable nor exposes its items under the constructor
     * parameter's name cannot be read back. That is a mismatch between the class and its schema, so it fails
     * loudly rather than emitting an empty list.
     */
    public function testAnOpaqueListValueObjectFailsLoudly(): void
    {
        $names = $this->schematic->map(OpaqueList::class, ['John Doe'])->value();

        $this->expectException(UnextractableValue::class);
        $serialized = $this->schematic->serialize(OpaqueList::class, $names);
        self::fail(sprintf('Expected the serialization to fail, but it produced %s', get_debug_type($serialized)));
    }
}
