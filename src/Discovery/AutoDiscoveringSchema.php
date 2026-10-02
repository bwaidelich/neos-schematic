<?php

declare(strict_types=1);

namespace Neos\Schematic\Discovery;

use Neos\JsonSchema\AnyOfSchema;
use Neos\JsonSchema\ArraySchema;
use Neos\JsonSchema\BooleanSchema;
use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\Nullable;
use Neos\JsonSchema\NumberSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;
use Neos\Schematic\Reflection\ClassShape;
use Neos\Schematic\Reflection\Nature;

/**
 * Derives a **pure** JSON Schema from a class, so a type that has nothing special to say can say
 * `AutoDiscoveringSchema::analyze(self::class)` instead of writing its schema out by hand.
 *
 * What it emits follows the class's {@see Nature} — the same reading {@see \Neos\Schematic\Schematic} builds and
 * serializes by, which is what keeps the published schema and the runtime behaviour the same thing:
 *
 * | Nature | Schema                                                                    |
 * | ------ | ------------------------------------------------------------------------- |
 * | Enum   | `string` / `integer` with `enum:` of the backing values, or the case names |
 * | Scalar | the scalar schema of its single parameter                                  |
 * | ListOf | an `array` whose `items` is the variadic parameter's type                  |
 * | Shape  | an `object` of its parameters, `additionalProperties: false`               |
 *
 * A nested type that implements {@see ProvidesSchema} contributes *its own* `schema()` verbatim; anything else is
 * analyzed in turn. Embedding rather than referencing is what makes the outer validation pass enforce the inner
 * class's invariants too, so one pass over a payload reports every issue in it.
 *
 * A member typed as a **union** of several types becomes an `anyOf` over their schemas — a value that may be one
 * of several shapes, described where it appears the same way a union *return* type is described in a document.
 *
 * The one attribute it reads is {@see Description}, because a description is an annotation rather than a rule: it
 * tells a reader what a member *means*, which no type can say, and changes nothing about which values pass. A
 * constraint that does not follow from the type is still written in the class's own `schema()`, which is the whole
 * point — the published schema and the enforced rule are one object.
 */
final class AutoDiscoveringSchema
{
    private function __construct() {}

    /**
     * @param class-string $className
     * @param list<class-string> $path the classes currently being analyzed, outermost first (recursion guard)
     */
    public static function analyze(string $className, array $path = []): Schema
    {
        if (in_array($className, $path, true)) {
            throw CircularReferenceException::forPath([...$path, $className]);
        }
        $path[] = $className;
        $shape = ClassShape::of($className);
        $schema = match ($shape->nature) {
            // is_subclass_of() rather than the ClassShape nature alone, because it narrows the class-string
            Nature::Enum => is_subclass_of($className, \UnitEnum::class)
                ? self::enum($className)
                : throw UnsupportedTypeException::because(sprintf('"%s" is not an enum', $className)),
            Nature::Scalar => self::parameterSchema($shape->parameters[0], $path),
            Nature::ListOf => ArraySchema::create(items: self::parameterSchema($shape->parameters[0], $path)),
            Nature::Shape => self::shape($shape, $path),
        };
        return self::describe($schema, new \ReflectionClass($className), sprintf('class "%s"', $className));
    }

    /**
     * The schema of a type as it appears *inside* another one: its own if it provides one, an analysis otherwise.
     *
     * @param class-string $className
     * @param list<class-string> $path
     */
    private static function schemaFor(string $className, array $path): Schema
    {
        if (is_a($className, ProvidesSchema::class, true)) {
            return $className::schema();
        }
        return self::analyze($className, $path);
    }

    /**
     * An enum is a scalar constrained to its cases: a *backed* enum by its backing values (the point of backing
     * it), a *pure* enum by its case names. The backing type picks the JSON type; everything else is a string.
     *
     * @param class-string<\UnitEnum> $className
     */
    private static function enum(string $className): Schema
    {
        $reflectionEnum = new \ReflectionEnum($className);
        $backingType = $reflectionEnum->getBackingType();
        if ($backingType instanceof \ReflectionNamedType && $backingType->getName() === 'int') {
            $intCases = [];
            foreach ($reflectionEnum->getCases() as $case) {
                /** @var \ReflectionEnumBackedCase $case */
                $intCases[] = (int) $case->getBackingValue();
            }
            self::rejectEmptyEnum($intCases, $className);
            return IntegerSchema::create(enum: $intCases);
        }
        $stringCases = [];
        foreach ($reflectionEnum->getCases() as $case) {
            $stringCases[] = $case instanceof \ReflectionEnumBackedCase ? (string) $case->getBackingValue() : $case->getName();
        }
        self::rejectEmptyEnum($stringCases, $className);
        return StringSchema::create(enum: $stringCases);
    }

    /**
     * @param list<string|int> $cases
     * @param class-string<\UnitEnum> $className
     */
    private static function rejectEmptyEnum(array $cases, string $className): void
    {
        if ($cases === []) {
            // `enum Foo {}` is legal PHP but describes a type no value can inhabit, so there is nothing to
            // validate against — fail loud rather than emit an `enum: []` that rejects everything.
            throw UnsupportedTypeException::because(sprintf('Enum "%s" declares no cases', $className));
        }
    }

    /**
     * @param list<class-string> $path
     */
    private static function shape(ClassShape $shape, array $path): Schema
    {
        $properties = [];
        $required = [];
        foreach ($shape->parameters as $parameter) {
            $name = $parameter->getName();
            $properties[$name] = self::parameterSchema($parameter, $path);
            if (!$parameter->isOptional()) {
                $required[] = $name;
            }
        }
        return ObjectSchema::create(
            properties: $properties === [] ? null : ObjectProperties::create(...$properties),
            additionalProperties: false,
            required: $required === [] ? null : $required,
        );
    }

    /**
     * @param list<class-string> $path
     */
    private static function parameterSchema(\ReflectionParameter $parameter, array $path): Schema
    {
        $type = $parameter->getType();
        if ($type instanceof \ReflectionUnionType) {
            return self::unionSchema($type, $parameter, $path);
        }
        if (!$type instanceof \ReflectionNamedType) {
            throw UnsupportedTypeException::because(sprintf('Unsupported type for parameter "$%s" (a named type or a union of them)', $parameter->getName()));
        }
        $schema = self::describe(self::namedTypeSchema($type, $parameter, $path), $parameter, sprintf('parameter "$%s"', $parameter->getName()));
        // `?Foo` and `Foo|null` are the same thing to reflection (both a ReflectionNamedType that allowsNull()), so
        // this one check covers both spellings; a union of two substantive branches is a ReflectionUnionType.
        return $type->allowsNull() ? Nullable::wrap($schema) : $schema;
    }

    /**
     * A member that is one of several types is an `anyOf` over their schemas — the same reading a union *return*
     * type gets in a published document, so a value that may be one of several shapes is described the same way
     * wherever it appears.
     *
     * `null` is not a branch of that union: it is the member being absent, which {@see Nullable} says, so
     * `Foo|Bar|null` is a nullable `anyOf` of two rather than an `anyOf` of three.
     *
     * @param list<class-string> $path
     */
    private static function unionSchema(\ReflectionUnionType $type, \ReflectionParameter $parameter, array $path): Schema
    {
        $branches = [];
        foreach ($type->getTypes() as $branch) {
            if (!$branch instanceof \ReflectionNamedType) {
                throw UnsupportedTypeException::because(sprintf('Unsupported branch of the union type of parameter "$%s" (intersection types are not supported)', $parameter->getName()));
            }
            if ($branch->getName() === 'null') {
                continue;
            }
            $branches[] = self::namedTypeSchema($branch, $parameter, $path);
        }
        if (count($branches) < 2) {
            throw UnsupportedTypeException::because(sprintf('The union type of parameter "$%s" has fewer than two substantive branches', $parameter->getName()));
        }
        if ($parameter->getAttributes(Description::class) !== []) {
            throw UnsupportedTypeException::because(sprintf('The union type of parameter "$%s" cannot carry a description: an `anyOf` has no `description` of its own', $parameter->getName()));
        }
        return $type->allowsNull() ? Nullable::wrap(AnyOfSchema::create(...$branches)) : AnyOfSchema::create(...$branches);
    }

    /**
     * The schema with the {@see Description} of the class or parameter it was derived for, if there is one.
     *
     * A nullable schema is described on its substantive branch, which is what a reader looks at to learn what the
     * member is. Anything else that has no `description` of its own — a genuine union, a `$ref` — fails loud rather
     * than dropping the description the class asked for.
     *
     * @param \ReflectionClass<object>|\ReflectionParameter $subject
     */
    private static function describe(Schema $schema, \ReflectionClass|\ReflectionParameter $subject, string $subjectLabel): Schema
    {
        $attributes = $subject->getAttributes(Description::class);
        if ($attributes === []) {
            return $schema;
        }
        return self::withDescription($schema, $attributes[0]->newInstance()->value)
            ?? throw UnsupportedTypeException::because(sprintf('The %s cannot carry a description: its schema has no `description` of its own', $subjectLabel));
    }

    private static function withDescription(Schema $schema, string $description): Schema|null
    {
        if (
            $schema instanceof StringSchema
            || $schema instanceof IntegerSchema
            || $schema instanceof NumberSchema
            || $schema instanceof BooleanSchema
            || $schema instanceof ArraySchema
            || $schema instanceof ObjectSchema
        ) {
            return $schema->with(description: $description);
        }
        $substantive = Nullable::unwrap($schema);
        if ($substantive === $schema) {
            return null;
        }
        $described = self::withDescription($substantive, $description);
        return $described === null ? null : Nullable::wrap($described);
    }

    /**
     * @param list<class-string> $path
     */
    private static function namedTypeSchema(\ReflectionNamedType $type, \ReflectionParameter $parameter, array $path): Schema
    {
        if (!$type->isBuiltin()) {
            /** @var class-string $typeName */
            $typeName = $type->getName();
            return self::schemaFor($typeName, $path);
        }
        // `null` and `mixed` also report allowsNull(), but neither describes a shape, so they fall through to the
        // default arm and are rejected rather than becoming "a nullable something".
        return match ($type->getName()) {
            'string' => StringSchema::create(),
            'int' => IntegerSchema::create(),
            'float' => NumberSchema::create(),
            'bool' => BooleanSchema::create(),
            default => throw UnsupportedTypeException::because(sprintf('Unsupported builtin type "%s" for parameter "$%s"', $type->getName(), $parameter->getName())),
        };
    }
}
