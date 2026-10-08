<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Discovery;

use Neos\JsonSchema\ObjectSchema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;
use Neos\Schematic\Discovery\CircularReferenceException;
use Neos\Schematic\Discovery\UnsupportedTypeException;
use Neos\Schematic\Tests\Discovery\Fixtures\Channel;
use Neos\Schematic\Tests\Discovery\Fixtures\GenuineUnion;
use Neos\Schematic\Tests\Discovery\Fixtures\MutuallyRecursiveA;
use Neos\Schematic\Tests\Discovery\Fixtures\Nothing;
use Neos\Schematic\Tests\Discovery\Fixtures\Post;
use Neos\Schematic\Tests\Discovery\Fixtures\PostWithDefaults;
use Neos\Schematic\Tests\Discovery\Fixtures\PostWithNullableSlug;
use Neos\Schematic\Tests\Discovery\Fixtures\Priority;
use Neos\Schematic\Tests\Discovery\Fixtures\SelfReferencing;
use Neos\Schematic\Tests\Discovery\Fixtures\Slug;
use Neos\Schematic\Tests\Discovery\Fixtures\Status;
use Neos\Schematic\Tests\Discovery\Fixtures\Title;
use Neos\Schematic\Tests\Discovery\Fixtures\Titles;
use Neos\Schematic\Tests\Discovery\Fixtures\Untyped;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Analysis reads a class and emits **pure** JSON Schema — no wrapper, no attributes, no codec.
 */
#[CoversClass(AutoDiscoveringSchema::class)]
#[CoversClass(UnsupportedTypeException::class)]
#[CoversClass(CircularReferenceException::class)]
final class AutoDiscoveringSchemaTest extends TestCase
{
    /**
     * @param class-string $className
     * @return array<mixed>
     */
    private function encoded(string $className): array
    {
        $decoded = json_decode((string) json_encode(AutoDiscoveringSchema::analyze($className), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    public function testAShapeIsAnObjectOfItsConstructorParameters(): void
    {
        $schema = $this->encoded(Post::class);

        self::assertSame('object', $schema['type']);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['slug', 'author', 'views', 'draft', 'rating'], array_keys((array) $schema['properties']));
        self::assertSame(['slug', 'author', 'views', 'draft', 'rating'], $schema['required']);
    }

    public function testEachBuiltinScalarBecomesItsJsonType(): void
    {
        $properties = (array) $this->encoded(Post::class)['properties'];

        self::assertSame(['type' => 'string'], $properties['author']);
        self::assertSame(['type' => 'integer'], $properties['views']);
        self::assertSame(['type' => 'boolean'], $properties['draft']);
        self::assertSame(['type' => 'number'], $properties['rating']);
    }

    /**
     * A type that provides its own schema is embedded verbatim — which is what makes the outer validation pass
     * enforce the inner class's invariant too.
     */
    public function testATypeProvidingItsOwnSchemaIsEmbedded(): void
    {
        $properties = (array) $this->encoded(Post::class)['properties'];

        self::assertSame(json_decode((string) json_encode(Slug::schema()), true), $properties['slug']);
    }

    public function testANestedTypeWithoutASchemaIsAnalyzedInTurn(): void
    {
        self::assertSame(['type' => 'string'], $this->encoded(Title::class));
    }

    public function testParametersWithDefaultsAreOptional(): void
    {
        $schema = $this->encoded(PostWithDefaults::class);

        self::assertSame(['author'], $schema['required']);
    }

    public function testANullableParameterGainsANullBranch(): void
    {
        $properties = (array) $this->encoded(PostWithNullableSlug::class)['properties'];

        self::assertSame(
            ['anyOf' => [json_decode((string) json_encode(Slug::schema()), true), ['type' => 'null']]],
            $properties['slug'],
        );
    }

    public function testAVariadicParameterMakesTheClassAnArrayOfItsItems(): void
    {
        $schema = $this->encoded(Titles::class);

        self::assertSame('array', $schema['type']);
        self::assertSame(['type' => 'string'], $schema['items']);
    }

    public function testASingleScalarParameterMakesTheClassThatScalar(): void
    {
        self::assertSame(['type' => 'string'], $this->encoded(Title::class));
    }

    public function testABackedEnumIsItsBackingValues(): void
    {
        self::assertSame(['type' => 'string', 'enum' => ['draft', 'published']], $this->encoded(Status::class));
        self::assertSame(['type' => 'integer', 'enum' => [1, 2]], $this->encoded(Priority::class));
    }

    public function testAPureEnumIsItsCaseNames(): void
    {
        self::assertSame(['type' => 'string', 'enum' => ['email', 'phone']], $this->encoded(Channel::class));
    }

    public function testAnEnumWithoutCasesIsRejected(): void
    {
        $this->expectException(UnsupportedTypeException::class);
        $this->expectExceptionMessageMatches('/declares no cases/');
        AutoDiscoveringSchema::analyze(Nothing::class);
    }

    /**
     * A member that may be one of several types is an `anyOf` over them — the same reading a union return type
     * gets in a document. `null` is not one of those types: it is the member being absent, which is what
     * {@see \Neos\JsonSchema\Nullable} says around the whole union rather than inside it.
     */
    public function testAGenuineUnionBecomesAnAnyOfOverItsBranches(): void
    {
        // Slug owns its schema and contributes it verbatim, Title has none and is analyzed — a union treats its
        // branches exactly as any other nested member position does
        self::assertSame(
            '{"type":"object","properties":{"either":{"anyOf":[{"type":"string"},{"type":"integer"}]},'
            . '"named":{"anyOf":[' . json_encode(Slug::schema()) . ',{"type":"string"},{"type":"null"}]}},'
            . '"additionalProperties":false,"required":["either"]}',
            json_encode(AutoDiscoveringSchema::analyze(GenuineUnion::class)),
        );
    }

    public function testABuiltinThatIsNotAScalarIsRejected(): void
    {
        $this->expectException(UnsupportedTypeException::class);
        $this->expectExceptionMessageMatches('/"array"/');
        AutoDiscoveringSchema::analyze(Untyped::class);
    }

    public function testASelfReferencingTypeIsRejectedWithItsPath(): void
    {
        $this->expectException(CircularReferenceException::class);
        $this->expectExceptionMessageMatches('/SelfReferencing -> .*SelfReferencing/');
        AutoDiscoveringSchema::analyze(SelfReferencing::class);
    }

    public function testACycleThroughSeveralClassesIsRejectedToo(): void
    {
        $this->expectException(CircularReferenceException::class);
        $this->expectExceptionMessageMatches('/MutuallyRecursiveA -> .*MutuallyRecursiveB -> .*MutuallyRecursiveA/');
        AutoDiscoveringSchema::analyze(MutuallyRecursiveA::class);
    }

    public function testAnalyzeShapeHandsBackTheObjectSchemaOfAShape(): void
    {
        $schema = AutoDiscoveringSchema::analyzeShape(Post::class);

        self::assertInstanceOf(ObjectSchema::class, $schema);
        self::assertSame(json_encode(AutoDiscoveringSchema::analyze(Post::class)), json_encode($schema));
    }

    public function testAnalyzeShapeCanBeDescribedExplicitly(): void
    {
        $schema = AutoDiscoveringSchema::analyzeShape(PostWithNullableSlug::class)
            ->with(description: 'A post')
            ->withPropertyDescriptions(slug: 'The address of the post');

        self::assertSame(
            '{"type":"object","description":"A post","properties":{"slug":{"anyOf":[{"type":"string","description":"The address of the post",'
            . '"minLength":1,"maxLength":100,"pattern":"^[a-z0-9-]+$"},{"type":"null"}]}},"additionalProperties":false,"required":["slug"]}',
            json_encode($schema),
        );
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function classesThatAreNoShape(): iterable
    {
        yield 'enum' => [Status::class];
        yield 'scalar' => [Title::class];
        yield 'list' => [Titles::class];
    }

    /**
     * @param class-string $className
     */
    #[DataProvider('classesThatAreNoShape')]
    public function testAnalyzeShapeRejectsAClassThatIsNoShape(string $className): void
    {
        $this->expectException(UnsupportedTypeException::class);
        $this->expectExceptionMessage('is not a shape');

        AutoDiscoveringSchema::analyzeShape($className);
    }

    public function testAnalyzeShapeStillRejectsASelfReferencingType(): void
    {
        $this->expectException(CircularReferenceException::class);

        AutoDiscoveringSchema::analyzeShape(SelfReferencing::class);
    }
}
