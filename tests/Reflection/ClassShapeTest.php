<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Reflection;

use Neos\Schematic\Reflection\ClassShape;
use Neos\Schematic\Reflection\Nature;
use Neos\Schematic\Tests\Discovery\Fixtures\Channel;
use Neos\Schematic\Tests\Discovery\Fixtures\Post;
use Neos\Schematic\Tests\Discovery\Fixtures\Status;
use Neos\Schematic\Tests\Discovery\Fixtures\Title;
use Neos\Schematic\Tests\Discovery\Fixtures\Titles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The one reading of a class the engine needs: what kind of thing it is, and which constructor parameters make it
 * up. Decided from the class alone — no attributes, no schema.
 */
#[CoversClass(ClassShape::class)]
#[CoversClass(Nature::class)]
final class ClassShapeTest extends TestCase
{
    public function testABackedEnumIsAnEnum(): void
    {
        $shape = ClassShape::of(Status::class);

        self::assertSame(Nature::Enum, $shape->nature);
        self::assertSame(Status::class, $shape->className);
        self::assertSame([], $shape->parameters);
    }

    public function testAPureEnumIsAnEnumToo(): void
    {
        self::assertSame(Nature::Enum, ClassShape::of(Channel::class)->nature);
    }

    /**
     * A single builtin-scalar parameter is the value-object idiom, and the whole class is that one value.
     */
    public function testOneScalarParameterIsAScalar(): void
    {
        $shape = ClassShape::of(Title::class);

        self::assertSame(Nature::Scalar, $shape->nature);
        self::assertCount(1, $shape->parameters);
        self::assertSame('value', $shape->parameters[0]->getName());
    }

    /**
     * Variadic is what distinguishes a collection from a shape with one array property — checked before the
     * scalar rule, so `string ...$parts` is a list rather than a scalar.
     */
    public function testOneVariadicParameterIsAList(): void
    {
        $shape = ClassShape::of(Titles::class);

        self::assertSame(Nature::ListOf, $shape->nature);
        self::assertSame('titles', $shape->parameters[0]->getName());
        self::assertTrue($shape->parameters[0]->isVariadic());
    }

    public function testAnythingElseIsAShape(): void
    {
        $shape = ClassShape::of(Post::class);

        self::assertSame(Nature::Shape, $shape->nature);
        self::assertSame(['slug', 'author', 'views', 'draft', 'rating'], array_map(static fn($p): string => $p->getName(), $shape->parameters));
    }

    /**
     * One class-typed parameter is *not* a scalar: only the builtin scalars collapse a class into its single
     * value, because only they arrive as a primitive.
     */
    public function testOneClassTypedParameterIsAShape(): void
    {
        self::assertSame(Nature::Shape, ClassShape::of(SingleValueObjectParameter::class)->nature);
    }

    public function testAClassWithoutAConstructorIsAShapeWithNoParameters(): void
    {
        $shape = ClassShape::of(NoConstructor::class);

        self::assertSame(Nature::Shape, $shape->nature);
        self::assertSame([], $shape->parameters);
    }

    public function testAParameterIsFoundByName(): void
    {
        $shape = ClassShape::of(Post::class);

        self::assertSame('author', $shape->parameter('author')?->getName());
        self::assertNull($shape->parameter('nope'));
    }

    public function testAnInterfaceHasNoShapeOfItsOwn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot be instantiated/');
        ClassShape::of(NotAClass::class);
    }

    public function testAnUnknownClassIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore argument.type */
        ClassShape::of('Neos\Schematic\Tests\NoSuchClass');
    }
}
