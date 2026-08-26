<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Serialization;

use Neos\Schematic\Instantiation\Mapper;
use Neos\Schematic\Serialization\Serializer;
use Neos\Schematic\Serialization\UnextractableValue;
use Neos\Schematic\Tests\Fixtures\Contact;
use Neos\Schematic\Tests\Fixtures\ContactName;
use Neos\Schematic\Tests\Fixtures\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * The engine-side Serializer, driven by hand-built Schema graphs (the engine ships no generator).
 */
final class SerializerTest extends TestCase
{
    public function testANodeWithoutAnExtractorPassesScalarsThrough(): void
    {
        $schema = Fixtures::contactName();
        self::assertNull($schema->extractor, 'the engine fixtures stamp no extractor');

        self::assertSame('Alice', (new Serializer())->serialize($schema, 'Alice'));
    }

    public function testANodeWithoutAnExtractorRecursesStructurally(): void
    {
        $result = (new Serializer())->serialize(Fixtures::contact(), ['name' => 'Alice', 'age' => 30]);

        self::assertSame(['name' => 'Alice', 'age' => 30], $result);
    }

    public function testANullableNodeSerializesNullWithoutConsultingTheExtractor(): void
    {
        $schema = Fixtures::contactName()->asNullable();

        self::assertNull((new Serializer())->serialize($schema, null));
    }

    public function testTheExtractorIsUsedWhenTheNodeNamesOne(): void
    {
        $schema = Fixtures::contactNameWithExtractor();

        $result = (new Serializer())->serialize($schema, new ContactName('Alice'));

        self::assertSame('Alice', $result);
    }

    public function testAnUnresolvableExtractorClassIsRejected(): void
    {
        $schema = Fixtures::contactNameWithBrokenExtractor();

        $this->expectException(\InvalidArgumentException::class);
        (new Serializer())->serialize($schema, new ContactName('Alice'));
    }

    /**
     * The round-trip property the whole design exists for: map input in, serialize back out, get the input.
     */
    public function testMappedInputSerializesBackToTheSameStructure(): void
    {
        $schema = Fixtures::contactWithExtractors();
        $input = ['name' => 'Alice', 'age' => 30];

        $result = (new Mapper())->map($schema, $input);
        self::assertTrue($result->success);
        self::assertInstanceOf(Contact::class, $result->value());

        self::assertSame($input, (new Serializer())->serialize($schema, $result->value()));
    }

    public function testAListSerializesBackToItsItems(): void
    {
        $schema = Fixtures::contactNamesWithExtractors();
        $input = ['Alice', 'Bob'];

        $result = (new Mapper())->map($schema, $input);
        self::assertTrue($result->success);

        self::assertSame($input, (new Serializer())->serialize($schema, $result->value()));
    }

    public function testAnExtractorThatCannotReadTheValueThrows(): void
    {
        $schema = Fixtures::contactNameWithExtractor();

        $this->expectException(UnextractableValue::class);
        (new Serializer())->serialize($schema, new \stdClass());
    }
}
