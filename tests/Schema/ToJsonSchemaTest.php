<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Schema;

use Neos\JsonSchema\ObjectSchema as JsonObjectSchema;
use Neos\Schematic\Tests\Fixtures\Fixtures;
use PHPUnit\Framework\TestCase;

final class ToJsonSchemaTest extends TestCase
{
    public function testRendersObjectSchemaWithAssembledProperties(): void
    {
        $json = Fixtures::contact()->toJsonSchema();

        self::assertInstanceOf(JsonObjectSchema::class, $json);
        self::assertSame(['name', 'age'], $json->properties?->names());
    }

    public function testRenderedJsonMatchesExpectedStructure(): void
    {
        $encoded = json_encode(Fixtures::contact()->toJsonSchema());
        self::assertIsString($encoded);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('object', $decoded['type']);
        self::assertSame(['name', 'age'], $decoded['required']);
        self::assertFalse($decoded['additionalProperties']);

        self::assertIsArray($decoded['properties']);
        self::assertIsArray($decoded['properties']['name']);
        self::assertSame('string', $decoded['properties']['name']['type']);
        self::assertSame(1, $decoded['properties']['name']['minLength']);
        self::assertIsArray($decoded['properties']['age']);
        self::assertSame('integer', $decoded['properties']['age']['type']);
    }

    public function testRendersListSchemaWithItems(): void
    {
        $encoded = json_encode(Fixtures::contactNames()->toJsonSchema());
        self::assertIsString($encoded);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('array', $decoded['type']);
        self::assertSame(1, $decoded['minItems']);
        self::assertIsArray($decoded['items']);
        self::assertSame('string', $decoded['items']['type']);
    }
}
