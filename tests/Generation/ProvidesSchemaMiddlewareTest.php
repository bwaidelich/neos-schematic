<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Generation;

use Neos\Schematic\Generation\Middleware\ProvidesSchemaMiddleware;
use Neos\Schematic\Generation\SchemaGenerator;
use Neos\Schematic\Generation\UnsupportedTypeException;
use Neos\Schematic\Instantiation\Mapper;
use Neos\Schematic\Schema\Kind;
use Neos\Schematic\Schema\Schema;
use Neos\Schematic\Serialization\ProvidesSchemaExtractor;
use Neos\Schematic\Serialization\Serializer;
use Neos\Schematic\Tests\Fixtures\Contact;
use Neos\Schematic\Tests\Fixtures\ProductId;
use PHPUnit\Framework\TestCase;

final class ProvidesSchemaMiddlewareTest extends TestCase
{
    private function next(): SchemaGenerator
    {
        return new class implements SchemaGenerator {
            public function generate(string $className): Schema
            {
                throw UnsupportedTypeException::forClass($className);
            }
        };
    }

    public function testBridgesProvidesSchemaClassIntoScalarSchema(): void
    {
        $schema = (new ProvidesSchemaMiddleware())->process(ProductId::class, $this->next(), $this->next());

        self::assertSame(Kind::Scalar, $schema->kind);
        self::assertSame(ProductId::class, $schema->target);
    }

    public function testBridgedSchemaAlsoSerializesBackOut(): void
    {
        $schema = (new ProvidesSchemaMiddleware())->process(ProductId::class, $this->next(), $this->next());
        self::assertSame(ProvidesSchemaExtractor::class, $schema->extractor);

        $productId = (new Mapper())->map($schema, 'p-abc123')->value();

        // `ProvidesSchema` defines only the way in, so the way back is a convention — here the $value property
        self::assertSame('p-abc123', (new Serializer())->serialize($schema, $productId));
    }

    public function testDelegatesForNonProvidesSchemaClass(): void
    {
        $this->expectException(UnsupportedTypeException::class);
        (new ProvidesSchemaMiddleware())->process(Contact::class, $this->next(), $this->next());
    }

    public function testMappedValidInputBecomesTheValueObject(): void
    {
        $schema = (new ProvidesSchemaMiddleware())->process(ProductId::class, $this->next(), $this->next());

        $result = (new Mapper())->map($schema, 'p-abc123');

        self::assertTrue($result->success);
        $productId = $result->value();
        self::assertInstanceOf(ProductId::class, $productId);
        self::assertSame('p-abc123', $productId->value);
    }

    public function testMappedInvalidInputReturnsIssuesWithoutInstantiating(): void
    {
        $schema = (new ProvidesSchemaMiddleware())->process(ProductId::class, $this->next(), $this->next());

        $result = (new Mapper())->map($schema, 'INVALID');

        self::assertFalse($result->success);
        self::assertCount(1, $result->issues);
    }

    public function testSelfValidationViaSchemaSugar(): void
    {
        self::assertTrue(ProductId::schema()->validate('p-abc123')->valid);
        self::assertFalse(ProductId::schema()->validate('INVALID')->valid);
    }
}
