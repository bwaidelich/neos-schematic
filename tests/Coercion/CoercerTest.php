<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Coercion;

use Neos\JsonSchema\Validation\IssueCode;
use Neos\Schematic\Coercion\Coercer;
use Neos\Schematic\Tests\Fixtures\Fixtures;
use PHPUnit\Framework\TestCase;

final class CoercerTest extends TestCase
{
    public function testCoercesValidObjectIntoNormalizedPrimitives(): void
    {
        $result = (new Coercer())->coerce(Fixtures::contact(), ['name' => 'John Doe', 'age' => 45]);

        self::assertTrue($result->success);
        self::assertSame(['name' => 'John Doe', 'age' => 45], $result->value());
    }

    public function testAggregatesAllViolations(): void
    {
        $result = (new Coercer())->coerce(Fixtures::contact(), ['name' => '', 'age' => 200]);

        self::assertFalse($result->success);
        self::assertCount(2, $result->issues);
    }

    public function testReportsMissingRequiredProperty(): void
    {
        $result = (new Coercer())->coerce(Fixtures::contact(), ['name' => 'John Doe']);

        self::assertFalse($result->success);
        $codes = array_map(static fn($issue): string => $issue->code, $result->issues->toArray());
        self::assertContains(IssueCode::Required->value, $codes);
    }

    public function testRejectsUnrecognizedProperties(): void
    {
        $result = (new Coercer())->coerce(Fixtures::contact(), ['name' => 'John Doe', 'age' => 1, 'extra' => true]);

        self::assertFalse($result->success);
        self::assertSame(IssueCode::UnrecognizedKeys->value, $result->issues->toArray()[0]->code);
    }

    public function testCoercesIntegerFromNumericString(): void
    {
        $result = (new Coercer())->coerce(Fixtures::contact(), ['name' => 'John Doe', 'age' => '45']);

        self::assertTrue($result->success);
        self::assertSame(['name' => 'John Doe', 'age' => 45], $result->value());
    }

    public function testIssuePathPointsAtOffendingProperty(): void
    {
        $result = (new Coercer())->coerce(Fixtures::contact(), ['name' => 'John Doe', 'age' => 200]);

        self::assertFalse($result->success);
        self::assertSame(['age'], $result->issues->toArray()[0]->path);
    }

    public function testCoercesList(): void
    {
        $result = (new Coercer())->coerce(Fixtures::contactNames(), ['Ada', 'Grace']);

        self::assertTrue($result->success);
        self::assertSame(['Ada', 'Grace'], $result->value());
    }

    public function testEmptyListViolatesMinItems(): void
    {
        $result = (new Coercer())->coerce(Fixtures::contactNames(), []);

        self::assertFalse($result->success);
        self::assertSame(IssueCode::TooFewItems->value, $result->issues->toArray()[0]->code);
    }

    public function testRejectsListWhereObjectExpected(): void
    {
        $result = (new Coercer())->coerce(Fixtures::contact(), ['John', 45]);

        self::assertFalse($result->success);
        self::assertSame(IssueCode::InvalidType->value, $result->issues->toArray()[0]->code);
    }
}
