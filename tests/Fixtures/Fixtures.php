<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\JsonSchema\ArraySchema as JsonArraySchema;
use Neos\JsonSchema\IntegerSchema as JsonIntegerSchema;
use Neos\JsonSchema\ObjectSchema as JsonObjectSchema;
use Neos\JsonSchema\StringSchema as JsonStringSchema;
use Neos\Schematic\Schema\Schema;

/**
 * Hand-built {@see Schema} graphs. Since the core ships no generator, tests construct schemas directly (as a
 * generation middleware would).
 */
final class Fixtures
{
    public static function contactName(): Schema
    {
        return Schema::scalar(
            JsonStringSchema::create(minLength: 1, maxLength: 200),
            ContactName::class,
            ContactNameFactory::class,
        );
    }

    public static function contactAge(): Schema
    {
        return Schema::scalar(
            JsonIntegerSchema::create(minimum: 0, maximum: 150),
            ContactAge::class,
            ContactAgeFactory::class,
        );
    }

    public static function contact(): Schema
    {
        return Schema::object(
            JsonObjectSchema::create(required: ['name', 'age'], additionalProperties: false),
            ['name' => self::contactName(), 'age' => self::contactAge()],
            Contact::class,
            ContactFactory::class,
        );
    }

    public static function contactNames(): Schema
    {
        return Schema::list(
            JsonArraySchema::create(minItems: 1),
            self::contactName(),
        );
    }

    /**
     * A shape with a nullable value-object property and a nullable plain scalar — the shape `NullableContact`
     * reflects to.
     */
    public static function nullableContact(): Schema
    {
        return Schema::object(
            JsonObjectSchema::create(required: ['name'], additionalProperties: false),
            [
                'name' => self::contactName(),
                'nickname' => self::contactName()->asNullable(),
                'note' => Schema::scalar(JsonStringSchema::create())->asNullable(),
            ],
            NullableContact::class,
            NullableContactFactory::class,
        );
    }

    /**
     * The same graphs, but with a ValueExtractor stamped alongside each InstanceFactory — what a middleware does
     * when it wants its schemas to round-trip.
     */
    public static function contactNameWithExtractor(): Schema
    {
        return Schema::scalar(
            JsonStringSchema::create(minLength: 1, maxLength: 200),
            ContactName::class,
            ContactNameFactory::class,
            ContactNameExtractor::class,
        );
    }

    public static function contactWithExtractors(): Schema
    {
        return Schema::object(
            JsonObjectSchema::create(required: ['name', 'age'], additionalProperties: false),
            [
                'name' => self::contactNameWithExtractor(),
                'age' => Schema::scalar(
                    JsonIntegerSchema::create(minimum: 0, maximum: 150),
                    ContactAge::class,
                    ContactAgeFactory::class,
                    ContactAgeExtractor::class,
                ),
            ],
            Contact::class,
            ContactFactory::class,
            ContactExtractor::class,
        );
    }

    /**
     * A structural list (no target of its own) whose *items* are value objects.
     */
    public static function contactNamesWithExtractors(): Schema
    {
        return Schema::list(
            JsonArraySchema::create(minItems: 1),
            self::contactNameWithExtractor(),
        );
    }

    /**
     * Names a class that exists but is not a ValueExtractor, to exercise the resolver's type check.
     */
    public static function contactNameWithBrokenExtractor(): Schema
    {
        return Schema::scalar(
            JsonStringSchema::create(minLength: 1, maxLength: 200),
            ContactName::class,
            ContactNameFactory::class,
            Contact::class,
        );
    }

    public static function dateRange(): Schema
    {
        return Schema::object(
            JsonObjectSchema::create(required: ['start', 'end'], additionalProperties: false),
            ['start' => Schema::scalar(JsonIntegerSchema::create()), 'end' => Schema::scalar(JsonIntegerSchema::create())],
            DateRange::class,
            DateRangeFactory::class,
        );
    }
}
