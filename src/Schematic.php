<?php

declare(strict_types=1);

namespace Neos\Schematic;

use Neos\Schematic\Coercion\Coercer;
use Neos\Schematic\Coercion\CoercionResult;
use Neos\Schematic\Generation\SchemaFactory;
use Neos\Schematic\Generation\SchemaMiddleware;
use Neos\Schematic\Instantiation\Instantiator;
use Neos\Schematic\Instantiation\Mapper;
use Neos\Schematic\Schema\Schema;
use Neos\Schematic\Serialization\Serializer;

/**
 * The entry point: wires the three services (generation, coercion, instantiation) into the two calls a consumer
 * usually makes — "give me the schema for this class" and "turn this input into an instance of this class".
 *
 * Wired once with the chosen {@see SchemaMiddleware} chain (a caching middleware typically first). The engine
 * itself ships no middleware that produces a schema from a plain class; the batteries (attributes + a reflecting
 * middleware) live in a namespace of their own and are opt-in — you name them here or not at all:
 *
 *     $schematic = Schematic::create(new CachingMiddleware($cache), new ReflectionMiddleware());
 *     $contact = $schematic->map(Contact::class, $input)->value();
 *
 * Each phase remains individually usable: {@see SchemaFactory}, {@see Coercer}, {@see Instantiator} and
 * {@see Serializer} can be injected and driven directly — this class adds no behaviour of its own.
 */
final class Schematic
{
    private readonly SchemaFactory $schemaFactory;
    private readonly Coercer $coercer;
    private readonly Mapper $mapper;
    private readonly Serializer $serializer;

    public function __construct(
        SchemaFactory $schemaFactory,
        Coercer|null $coercer = null,
        Instantiator|null $instantiator = null,
        Serializer|null $serializer = null,
    ) {
        $this->schemaFactory = $schemaFactory;
        $this->coercer = $coercer ?? new Coercer();
        $this->mapper = new Mapper($this->coercer, $instantiator ?? new Instantiator());
        $this->serializer = $serializer ?? new Serializer();
    }

    /**
     * Wires a Schematic with the given middleware chain and the default coercion / instantiation services.
     */
    public static function create(SchemaMiddleware ...$middlewares): self
    {
        return new self(new SchemaFactory(...$middlewares));
    }

    /**
     * The Schema for a class, as produced by the middleware chain (renderable via {@see Schema::toJsonSchema()}).
     *
     * @param class-string $className
     * @throws Generation\UnsupportedTypeException if no middleware produced a schema for the class
     */
    public function schemaFor(string $className): Schema
    {
        return $this->schemaFactory->for($className);
    }

    /**
     * Both phases: coerce the input against the class's schema, then build the instance.
     *
     * @param class-string $className
     */
    #[\NoDiscard('inspect the CoercionResult; discarding it drops the mapping outcome')]
    public function map(string $className, mixed $input): CoercionResult
    {
        return $this->mapper->map($this->schemaFor($className), $input);
    }

    /**
     * Phase 1 only: validate + normalize the input into schema-conformant primitives, creating no instances.
     *
     * @param class-string $className
     */
    #[\NoDiscard('inspect the CoercionResult; discarding it means the coercion was pointless')]
    public function coerce(string $className, mixed $input): CoercionResult
    {
        return $this->coercer->coerce($this->schemaFor($className), $input);
    }

    /**
     * The way back out: read an instance into schema-conformant primitives, ready for `json_encode()`.
     *
     * Driven by the same Schema that {@see self::map()} maps *into* and that `toJsonSchema()` renders, so the
     * emitted structure cannot drift away from the advertised schema.
     *
     * @param class-string $className
     * @throws Serialization\UnextractableValue if the instance does not expose the state the schema describes
     */
    #[\NoDiscard('inspect the returned primitives; discarding them means the serialization was pointless')]
    public function serialize(string $className, mixed $value): mixed
    {
        return $this->serializer->serialize($this->schemaFor($className), $value);
    }
}
