<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes;

use Neos\JsonSchema\OneOfSchema as JsonOneOfSchema;
use Neos\JsonSchema\Validation\IssueCode;
use Neos\Schematic\Attributes\ReflectionMiddleware;
use Neos\Schematic\Generation\UnsupportedTypeException;
use Neos\Schematic\Schema\Kind;
use Neos\Schematic\Schematic;
use Neos\Schematic\Tests\Attributes\Fixtures\Circle;
use Neos\Schematic\Tests\Attributes\Fixtures\ClashingTag;
use Neos\Schematic\Tests\Attributes\Fixtures\Drawing;
use Neos\Schematic\Tests\Attributes\Fixtures\MisMapped;
use Neos\Schematic\Tests\Attributes\Fixtures\Rectangle;
use Neos\Schematic\Tests\Attributes\Fixtures\Shape;
use Neos\Schematic\Tests\Attributes\Fixtures\UndiscriminatedShape;
use PHPUnit\Framework\TestCase;

final class DiscriminatorTest extends TestCase
{
    private Schematic $schematic;

    protected function setUp(): void
    {
        $this->schematic = Schematic::create(new ReflectionMiddleware());
    }

    public function testAnInterfaceWithADiscriminatorBecomesAUnion(): void
    {
        $schema = $this->schematic->schemaFor(Shape::class);

        self::assertSame(Kind::Union, $schema->kind);
        self::assertSame(Shape::class, $schema->target);
        self::assertSame('type', $schema->discriminatorPropertyName());
        self::assertSame(['circle', 'rectangle'], array_keys($schema->candidates));
    }

    public function testAnInterfaceWithoutADiscriminatorIsStillUnsupported(): void
    {
        $this->expectException(UnsupportedTypeException::class);
        $this->schematic->schemaFor(UndiscriminatedShape::class);
    }

    public function testAMappingToANonImplementationIsRejected(): void
    {
        $this->expectException(UnsupportedTypeException::class);
        $this->schematic->schemaFor(MisMapped::class);
    }

    /**
     * The discriminator needs its own key; a candidate that already declares a property of that name would fight
     * with it, so the clash is caught at generation time rather than producing a payload nobody can read.
     */
    public function testACandidateThatAlreadyUsesTheTagNameIsRejected(): void
    {
        $this->expectException(UnsupportedTypeException::class);
        $this->schematic->schemaFor(ClashingTag::class);
    }

    /**
     * Each candidate pins the tag as a required `const`, which is what makes the rendered `oneOf` resolvable by a
     * plain JSON Schema validator — without needing the OpenAPI `discriminator` keyword.
     */
    public function testEachCandidatePinsTheTagAsARequiredConst(): void
    {
        $decoded = json_decode(
            (string) json_encode($this->schematic->schemaFor(Shape::class)->toJsonSchema()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decoded);
        self::assertIsArray($decoded['oneOf']);
        $circle = $decoded['oneOf'][0];
        self::assertIsArray($circle);
        self::assertIsArray($circle['properties']);
        self::assertSame(['type' => 'string', 'const' => 'circle'], $circle['properties']['type']);
        self::assertSame(['type', 'radius'], $circle['required']);
    }

    public function testTheRenderedSchemaCarriesTheDiscriminatorMapping(): void
    {
        $jsonSchema = $this->schematic->schemaFor(Shape::class)->toJsonSchema();
        self::assertInstanceOf(JsonOneOfSchema::class, $jsonSchema);

        self::assertNotNull($jsonSchema->discriminator);
        self::assertSame('type', $jsonSchema->discriminator->propertyName);
        self::assertSame(
            ['circle' => Circle::class, 'rectangle' => Rectangle::class],
            $jsonSchema->discriminator->mapping,
        );
    }

    public function testMapsAPayloadToTheDiscriminatedImplementation(): void
    {
        $result = $this->schematic->map(Shape::class, ['type' => 'circle', 'radius' => 5]);

        self::assertTrue($result->success);
        $circle = $result->value();
        self::assertInstanceOf(Circle::class, $circle);
        self::assertSame(5, $circle->radius->value);
    }

    public function testMapsTheOtherImplementation(): void
    {
        $result = $this->schematic->map(Shape::class, ['type' => 'rectangle', 'width' => 3, 'height' => 4]);

        self::assertTrue($result->success);
        $rectangle = $result->value();
        self::assertInstanceOf(Rectangle::class, $rectangle);
        self::assertSame(3, $rectangle->width->value);
        self::assertNull($rectangle->label);
    }

    public function testAMissingDiscriminatorIsReportedAtItsOwnPath(): void
    {
        $result = $this->schematic->map(Shape::class, ['radius' => 5]);

        self::assertFalse($result->success);
        $issues = iterator_to_array($result->issues);
        self::assertSame(['type'], $issues[0]->path);
        self::assertSame(IssueCode::InvalidEnumValue->value, $issues[0]->code);
        self::assertSame('Discriminator "type" must be one of "circle", "rectangle"', $issues[0]->message);
    }

    public function testAnUnknownDiscriminatorValueIsRejected(): void
    {
        $result = $this->schematic->map(Shape::class, ['type' => 'triangle', 'radius' => 5]);

        self::assertFalse($result->success);
        $issues = iterator_to_array($result->issues);
        self::assertSame(['type'], $issues[0]->path);
        self::assertSame(IssueCode::InvalidEnumValue->value, $issues[0]->code);
    }

    /**
     * The precision that made this worth doing: once the discriminator has selected a branch, a violation inside
     * that branch is reported as itself, not as a blanket "matched none of 2".
     */
    public function testAViolationInsideTheSelectedCandidateIsReportedPrecisely(): void
    {
        $result = $this->schematic->map(Shape::class, ['type' => 'circle', 'radius' => 500]);

        self::assertFalse($result->success);
        $issues = iterator_to_array($result->issues);
        self::assertCount(1, $issues);
        self::assertSame(['radius'], $issues[0]->path);
        self::assertSame(IssueCode::TooBig->value, $issues[0]->code);
    }

    public function testAPropertyBelongingToAnotherCandidateIsRejected(): void
    {
        $result = $this->schematic->map(Shape::class, ['type' => 'circle', 'radius' => 5, 'width' => 3]);

        self::assertFalse($result->success);
        $issues = iterator_to_array($result->issues);
        self::assertSame(IssueCode::UnrecognizedKeys->value, $issues[0]->code);
    }

    public function testSerializesBackWithTheDiscriminatorRestored(): void
    {
        $input = ['type' => 'rectangle', 'width' => 3, 'height' => 4, 'label' => 'a box'];
        $rectangle = $this->schematic->map(Shape::class, $input)->value();

        self::assertSame($input, $this->schematic->serialize(Shape::class, $rectangle));
    }

    /**
     * A union nested as a property is the case the Coercer's three-pass traversal has to resolve mid-flight.
     */
    public function testAUnionNestedInAShapeRoundTrips(): void
    {
        $input = ['title' => 'My drawing', 'shape' => ['type' => 'circle', 'radius' => 7]];

        $drawing = $this->schematic->map(Drawing::class, $input)->value();
        self::assertInstanceOf(Drawing::class, $drawing);
        self::assertInstanceOf(Circle::class, $drawing->shape);

        self::assertSame($input, $this->schematic->serialize(Drawing::class, $drawing));
    }

    public function testAViolationInsideANestedUnionKeepsItsFullPath(): void
    {
        $result = $this->schematic->map(Drawing::class, [
            'title' => 'My drawing',
            'shape' => ['type' => 'circle', 'radius' => 500],
        ]);

        self::assertFalse($result->success);
        $issues = iterator_to_array($result->issues);
        self::assertSame(['shape', 'radius'], $issues[0]->path);
        self::assertSame(IssueCode::TooBig->value, $issues[0]->code);
    }

    public function testTheSerializedPayloadValidatesAgainstTheAdvertisedSchema(): void
    {
        $drawing = $this->schematic->map(Drawing::class, [
            'title' => 'My drawing',
            'shape' => ['type' => 'circle', 'radius' => 7],
        ])->value();

        $primitives = $this->schematic->serialize(Drawing::class, $drawing);

        self::assertTrue($this->schematic->schemaFor(Drawing::class)->toJsonSchema()->validate($primitives)->valid);
    }
}
