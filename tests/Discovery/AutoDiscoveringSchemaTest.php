<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Discovery;

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

    public function testAGenuineUnionIsRejected(): void
    {
        $this->expectException(UnsupportedTypeException::class);
        $this->expectExceptionMessageMatches('/only single named types/');
        AutoDiscoveringSchema::analyze(GenuineUnion::class);
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
}
