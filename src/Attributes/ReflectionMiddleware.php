<?php

declare(strict_types=1);

namespace Neos\Schematic\Attributes;

use Neos\JsonSchema\ArraySchema as JsonArraySchema;
use Neos\JsonSchema\BooleanSchema as JsonBooleanSchema;
use Neos\JsonSchema\IntegerSchema as JsonIntegerSchema;
use Neos\JsonSchema\NumberSchema as JsonNumberSchema;
use Neos\JsonSchema\ObjectSchema as JsonObjectSchema;
use Neos\JsonSchema\OneOfSchema as JsonOneOfSchema;
use Neos\JsonSchema\Schema as JsonSchema;
use Neos\JsonSchema\StringSchema as JsonStringSchema;
use Neos\JsonSchema\Support\Discriminator as JsonDiscriminator;
use Neos\Schematic\Attributes\Instantiation\EnumFactory;
use Neos\Schematic\Attributes\Instantiation\ListFactory;
use Neos\Schematic\Attributes\Instantiation\ShapeFactory;
use Neos\Schematic\Attributes\Instantiation\ValueFactory;
use Neos\Schematic\Attributes\Serialization\EnumExtractor;
use Neos\Schematic\Attributes\Serialization\ListExtractor;
use Neos\Schematic\Attributes\Serialization\ScalarExtractor;
use Neos\Schematic\Attributes\Serialization\ShapeExtractor;
use Neos\Schematic\Generation\SchemaGenerator;
use Neos\Schematic\Generation\SchemaMiddleware;
use Neos\Schematic\Generation\UnsupportedTypeException;
use Neos\Schematic\Schema\Kind;
use Neos\Schematic\Schema\Schema;
use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionNamedType;
use ReflectionParameter;
use UnitEnum;

/**
 * The reference generation middleware. Reflects a class and its {@see StringBased}, {@see IntegerBased},
 * {@see FloatBased}, {@see ListBased}, {@see Description} attributes (or its constructor, for a plain shape; or its
 * cases, for an enum) into a {@see Schema} graph, stamping the matching default
 * {@see \Neos\Schematic\Instantiation\InstanceFactory} *and* its {@see \Neos\Schematic\Serialization\ValueExtractor}
 * counterpart, so every schema it produces round-trips. Nested class types are resolved by re-entering the root
 * pipeline.
 *
 * An interface or abstract class carrying {@see Discriminator} becomes a union of its mapped implementations;
 * without that attribute it is delegated to the next middleware.
 */
final class ReflectionMiddleware implements SchemaMiddleware
{
    public function process(string $className, SchemaGenerator $next, SchemaGenerator $root): Schema
    {
        // interface_exists() too: class_exists() is false for an interface, which would delegate it before the
        // discriminator below is ever considered
        if (!class_exists($className) && !interface_exists($className)) {
            return $next->generate($className);
        }
        $reflectionClass = new ReflectionClass($className);
        if ($reflectionClass->isInterface() || $reflectionClass->isAbstract()) {
            $discriminator = $this->attribute($reflectionClass, Discriminator::class);
            if ($discriminator === null) {
                return $next->generate($className);
            }
            return $this->union($className, $discriminator, $root);
        }
        $description = $this->description($reflectionClass);
        // PHP forbids a normal class from implementing UnitEnum, so this is exactly "is an enum" — and unlike
        // ReflectionClass::isEnum() it narrows the class-string for the ReflectionEnum below.
        if (is_subclass_of($className, UnitEnum::class)) {
            return $this->enum($className, $description);
        }

        $stringBased = $this->attribute($reflectionClass, StringBased::class);
        if ($stringBased !== null) {
            return Schema::scalar(
                JsonStringSchema::create(description: $description, minLength: $stringBased->minLength, maxLength: $stringBased->maxLength, pattern: $stringBased->pattern, format: $stringBased->format),
                $className,
                ValueFactory::class,
                ScalarExtractor::class,
            );
        }
        $integerBased = $this->attribute($reflectionClass, IntegerBased::class);
        if ($integerBased !== null) {
            return Schema::scalar(
                JsonIntegerSchema::create(description: $description, minimum: $integerBased->minimum, maximum: $integerBased->maximum),
                $className,
                ValueFactory::class,
                ScalarExtractor::class,
            );
        }
        $floatBased = $this->attribute($reflectionClass, FloatBased::class);
        if ($floatBased !== null) {
            return Schema::scalar(
                JsonNumberSchema::create(description: $description, minimum: $floatBased->minimum, maximum: $floatBased->maximum),
                $className,
                ValueFactory::class,
                ScalarExtractor::class,
            );
        }
        $listBased = $this->attribute($reflectionClass, ListBased::class);
        if ($listBased !== null) {
            return Schema::list(
                JsonArraySchema::create(description: $description, minItems: $listBased->minCount, maxItems: $listBased->maxCount),
                $root->generate($listBased->itemClassName),
                $className,
                ListFactory::class,
                ListExtractor::class,
            );
        }
        return $this->shape($reflectionClass, $className, $description, $root);
    }

    /**
     * A polymorphic type: each mapped implementation is generated through the root pipeline, and its schema gains
     * the discriminator as a **required `const` property**.
     *
     * Pinning the tag inside every candidate is what keeps the whole thing plain JSON Schema: the rendered `oneOf`
     * is then unambiguous on its own — a validator can pick the branch from the payload without the OpenAPI
     * `discriminator` keyword — and the tag survives coercion, so the Instantiator knows what to build.
     *
     * (A `oneOf` carries no `description` of its own, so a `#[Description]` on the interface has nowhere to go —
     * the descriptions of the candidates are what a reader sees.)
     *
     * @param class-string $className
     */
    private function union(string $className, Discriminator $discriminator, SchemaGenerator $root): Schema
    {
        $candidates = [];
        foreach ($discriminator->mapping as $value => $implementationClassName) {
            // checked before generating, so a typo in the mapping fails immediately rather than after building a
            // schema that could never be selected
            if (!is_a($implementationClassName, $className, true)) {
                throw new UnsupportedTypeException(sprintf('Discriminator mapping "%s" of "%s" refers to "%s", which is not an implementation of it', $value, $className, $implementationClassName));
            }
            $candidates[$value] = $this->withDiscriminatorProperty($root->generate($implementationClassName), $discriminator->propertyName, $value, $className);
        }
        return Schema::union(
            JsonOneOfSchema::create()->withDiscriminator(new JsonDiscriminator($discriminator->propertyName, $discriminator->mapping)),
            $candidates,
            $className,
        );
    }

    /**
     * @param class-string $unionClassName
     */
    private function withDiscriminatorProperty(Schema $candidate, string $propertyName, string $value, string $unionClassName): Schema
    {
        if ($candidate->kind !== Kind::Object || !$candidate->jsonSchema instanceof JsonObjectSchema) {
            throw new UnsupportedTypeException(sprintf('Candidate "%s" of the "%s" union must be a shape, so that the "%s" discriminator can live on it', (string) $candidate->target, $unionClassName, $propertyName));
        }
        if (isset($candidate->properties[$propertyName])) {
            // Otherwise the tag and a real property of the same name would fight over one key.
            throw new UnsupportedTypeException(sprintf('Candidate "%s" of the "%s" union already declares a property "%s", which the discriminator needs', (string) $candidate->target, $unionClassName, $propertyName));
        }
        $required = $candidate->jsonSchema->required ?? [];
        return Schema::object(
            $candidate->jsonSchema->with(required: [$propertyName, ...$required]),
            [$propertyName => Schema::scalar(JsonStringSchema::create(const: $value)), ...$candidate->properties],
            $candidate->target,
            $candidate->factory,
            $candidate->extractor,
        );
    }

    /**
     * An enum is a scalar constrained to its cases: a *backed* enum by its backing values (the point of backing
     * it), a *pure* enum by its case names. The backing type picks the JSON Schema type; everything else is a
     * string.
     *
     * @param class-string<UnitEnum> $className
     */
    private function enum(string $className, string|null $description): Schema
    {
        $reflectionEnum = new ReflectionEnum($className);
        $backingType = $reflectionEnum->getBackingType();
        if ($backingType instanceof ReflectionNamedType && $backingType->getName() === 'int') {
            $intCases = [];
            foreach ($reflectionEnum->getCases() as $case) {
                /** @var ReflectionEnumBackedCase $case */
                $intCases[] = (int) $case->getBackingValue();
            }
            return $this->enumScalar(JsonIntegerSchema::create(description: $description, enum: $intCases), $intCases, $className);
        }
        $stringCases = [];
        foreach ($reflectionEnum->getCases() as $case) {
            $stringCases[] = $case instanceof ReflectionEnumBackedCase ? (string) $case->getBackingValue() : $case->getName();
        }
        return $this->enumScalar(JsonStringSchema::create(description: $description, enum: $stringCases), $stringCases, $className);
    }

    /**
     * @param list<string|int> $cases
     * @param class-string $className
     */
    private function enumScalar(JsonSchema $jsonSchema, array $cases, string $className): Schema
    {
        if ($cases === []) {
            // `enum Foo {}` is legal PHP but describes a type no value can inhabit, so there is nothing to
            // validate against — fail loud rather than emit an `enum: []` that rejects everything.
            throw new UnsupportedTypeException(sprintf('Enum "%s" declares no cases', $className));
        }
        return Schema::scalar($jsonSchema, $className, EnumFactory::class, EnumExtractor::class);
    }

    /**
     * @param ReflectionClass<object> $reflectionClass
     * @param class-string $className
     */
    private function shape(ReflectionClass $reflectionClass, string $className, string|null $description, SchemaGenerator $root): Schema
    {
        $constructor = $reflectionClass->getConstructor();
        $properties = [];
        $required = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            if ($parameter->getAttributes(Ignore::class) !== []) {
                continue;
            }
            $name = $parameter->getName();
            $properties[$name] = $this->parameterSchema($parameter, $root);
            if (!$parameter->isOptional()) {
                $required[] = $name;
            }
        }
        return Schema::object(
            JsonObjectSchema::create(description: $description, additionalProperties: false, required: $required === [] ? null : $required),
            $properties,
            $className,
            ShapeFactory::class,
            ShapeExtractor::class,
        );
    }

    private function parameterSchema(ReflectionParameter $parameter, SchemaGenerator $root): Schema
    {
        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType) {
            throw new UnsupportedTypeException(sprintf('Unsupported type for parameter "$%s" (only single named types are supported)', $parameter->getName()));
        }
        $schema = $this->namedTypeSchema($type, $parameter, $root);
        // `?Foo` and `Foo|null` are the same thing to reflection (both a ReflectionNamedType with allowsNull()), so
        // this one check covers both spellings. Genuine unions are ReflectionUnionType and rejected above.
        return $type->allowsNull() ? $schema->asNullable() : $schema;
    }

    private function namedTypeSchema(ReflectionNamedType $type, ReflectionParameter $parameter, SchemaGenerator $root): Schema
    {
        if (!$type->isBuiltin()) {
            /** @var class-string $typeName */
            $typeName = $type->getName();
            return $root->generate($typeName);
        }
        // `null` and `mixed` also report allowsNull(), but neither describes a shape, so they fall through to the
        // default arm and are rejected rather than becoming "a nullable something".
        return match ($type->getName()) {
            'string' => Schema::scalar(JsonStringSchema::create()),
            'int' => Schema::scalar(JsonIntegerSchema::create()),
            'float' => Schema::scalar(JsonNumberSchema::create()),
            'bool' => Schema::scalar(JsonBooleanSchema::create()),
            default => throw new UnsupportedTypeException(sprintf('Unsupported builtin type "%s" for parameter "$%s"', $type->getName(), $parameter->getName())),
        };
    }

    /**
     * @param ReflectionClass<object> $reflectionClass
     */
    private function description(ReflectionClass $reflectionClass): string|null
    {
        return $this->attribute($reflectionClass, Description::class)?->value;
    }

    /**
     * @template T of object
     * @param ReflectionClass<object> $reflectionClass
     * @param class-string<T> $attributeClassName
     * @return T|null
     */
    private function attribute(ReflectionClass $reflectionClass, string $attributeClassName): object|null
    {
        $attributes = $reflectionClass->getAttributes($attributeClassName);
        if ($attributes === []) {
            return null;
        }
        return $attributes[0]->newInstance();
    }
}
