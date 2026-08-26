<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Instantiation;

use Neos\Schematic\Coercion\InvalidValue;
use Neos\Schematic\Instantiation\Mapper;
use Neos\Schematic\Tests\Fixtures\Contact;
use Neos\Schematic\Tests\Fixtures\ContactName;
use Neos\Schematic\Tests\Fixtures\Fixtures;
use PHPUnit\Framework\TestCase;

final class MapperTest extends TestCase
{
    public function testMapsInputIntoObjectGraph(): void
    {
        $result = (new Mapper())->map(Fixtures::contact(), ['name' => 'John Doe', 'age' => 45]);

        self::assertTrue($result->success);
        $contact = $result->value();
        self::assertInstanceOf(Contact::class, $contact);
        self::assertSame('John Doe', $contact->name->value);
        self::assertSame(45, $contact->age->value);
    }

    public function testReturnsIssuesWithoutInstantiatingOnInvalidInput(): void
    {
        $result = (new Mapper())->map(Fixtures::contact(), ['name' => '', 'age' => 45]);

        self::assertFalse($result->success);
        self::assertCount(1, $result->issues);
    }

    public function testConvertsConstructionFailureIntoIssue(): void
    {
        $result = (new Mapper())->map(Fixtures::dateRange(), ['start' => 10, 'end' => 5]);

        self::assertFalse($result->success);
        self::assertSame(InvalidValue::ISSUE_CODE, $result->issues->toArray()[0]->code);
    }

    public function testMapsListOfValueObjects(): void
    {
        $result = (new Mapper())->map(Fixtures::contactNames(), ['Ada', 'Grace']);

        self::assertTrue($result->success);
        $list = $result->value();
        self::assertIsArray($list);
        self::assertContainsOnlyInstancesOf(ContactName::class, $list);
        self::assertSame('Ada', $list[0]->value);
    }
}
