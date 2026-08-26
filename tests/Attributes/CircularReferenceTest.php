<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes;

use Neos\Schematic\Attributes\ReflectionMiddleware;
use Neos\Schematic\Generation\CircularReferenceException;
use Neos\Schematic\Generation\SchemaFactory;
use Neos\Schematic\Tests\Attributes\Fixtures\ContactName;
use Neos\Schematic\Tests\Attributes\Fixtures\MutuallyRecursiveCategories;
use Neos\Schematic\Tests\Attributes\Fixtures\MutuallyRecursiveCategory;
use Neos\Schematic\Tests\Attributes\Fixtures\NullableContact;
use Neos\Schematic\Tests\Attributes\Fixtures\SelfReferencingNode;
use PHPUnit\Framework\TestCase;

/**
 * The recursion guard as reached through real reflected classes. Nullability support makes
 * `SelfReferencingNode|null $parent` an entirely ordinary thing to write, so this is the shape a user is most
 * likely to hit — it must fail with a diagnosis rather than exhausting the stack.
 */
final class CircularReferenceTest extends TestCase
{
    private SchemaFactory $schemaFactory;

    protected function setUp(): void
    {
        $this->schemaFactory = new SchemaFactory(new ReflectionMiddleware());
    }

    public function testANullableSelfReferenceIsRejected(): void
    {
        $this->expectException(CircularReferenceException::class);
        $this->schemaFactory->for(SelfReferencingNode::class);
    }

    public function testACycleThroughAListBasedClassIsRejected(): void
    {
        try {
            $this->schemaFactory->for(MutuallyRecursiveCategory::class);
            self::fail('Expected a CircularReferenceException');
        } catch (CircularReferenceException $exception) {
            self::assertSame(
                sprintf(
                    'Circular reference while generating a schema: %s -> %s -> %s. Recursive types are not supported.',
                    MutuallyRecursiveCategory::class,
                    MutuallyRecursiveCategories::class,
                    MutuallyRecursiveCategory::class,
                ),
                $exception->getMessage(),
            );
        }
    }

    public function testTheSameValueObjectUsedForTwoPropertiesIsNotACycle(): void
    {
        $schema = $this->schemaFactory->for(NullableContact::class);

        self::assertSame(ContactName::class, $schema->properties['name']->target);
        self::assertSame(ContactName::class, $schema->properties['nickname']->target);
    }
}
