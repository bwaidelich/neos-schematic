<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests;

use Neos\JsonSchema\Validation\IssueCode;
use Neos\Schematic\Generation\UnsupportedTypeException;
use Neos\Schematic\Schematic;
use Neos\Schematic\Tests\Fixtures\Contact;
use Neos\Schematic\Tests\Fixtures\ContactMiddleware;
use Neos\Schematic\Tests\Fixtures\ContactName;
use Neos\Schematic\Tests\Fixtures\ProductId;
use PHPUnit\Framework\TestCase;

/**
 * Covers the entry point with a hand-written middleware only — no attributes involved, mirroring the "engine
 * usable on its own" promise.
 */
final class SchematicTest extends TestCase
{
    private Schematic $schematic;

    protected function setUp(): void
    {
        $this->schematic = Schematic::create(new ContactMiddleware());
    }

    public function testMapsInputToAnInstance(): void
    {
        $result = $this->schematic->map(Contact::class, ['name' => 'John Doe', 'age' => 45]);

        self::assertTrue($result->success);
        $contact = $result->value();
        self::assertInstanceOf(Contact::class, $contact);
        self::assertSame('John Doe', $contact->name->value);
        self::assertSame(45, $contact->age->value);
    }

    public function testMapReportsIssuesInsteadOfThrowing(): void
    {
        $result = $this->schematic->map(Contact::class, ['name' => '', 'age' => 500]);

        self::assertFalse($result->success);
        self::assertCount(2, $result->issues);
    }

    public function testCoerceNormalizesWithoutInstantiating(): void
    {
        $result = $this->schematic->coerce(Contact::class, ['name' => 'John Doe', 'age' => '45']);

        self::assertTrue($result->success);
        self::assertSame(['name' => 'John Doe', 'age' => 45], $result->value());
    }

    public function testCoerceReportsIssues(): void
    {
        $result = $this->schematic->coerce(ContactName::class, '');

        self::assertFalse($result->success);
        self::assertSame(IssueCode::TooShort->value, $result->issues->toArray()[0]->code);
    }

    public function testSchemaForRendersToJsonSchema(): void
    {
        $encoded = json_encode($this->schematic->schemaFor(Contact::class)->toJsonSchema());
        self::assertIsString($encoded);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('object', $decoded['type']);
        self::assertSame(['name', 'age'], $decoded['required']);
    }

    public function testUnhandledClassFailsLoud(): void
    {
        $this->expectException(UnsupportedTypeException::class);
        $this->schematic->schemaFor(ProductId::class);
    }
}
