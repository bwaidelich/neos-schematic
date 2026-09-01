<?php

declare(strict_types=1);

namespace Neos\Schematic;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Instantiation\Instantiator;
use Neos\Schematic\Serialization\Serializer;

/**
 * The bridge between PHP classes and JSON Schema — and nothing else. A type owns its schema by returning it from a
 * static `schema()`, and a type that does not is refused rather than guessed at; there is no registry, no
 * container and no state of its own to wire up, so every entry point here is static:
 *
 *     final readonly class Slug implements ProvidesSchema
 *     {
 *         private function __construct(public string $value) {}
 *
 *         public static function fromString(string $value): self
 *         {
 *             return Schematic::instanciate(self::class, $value)->valueOrThrow();
 *         }
 *
 *         public static function schema(): Schema
 *         {
 *             static $schema = null;
 *             return $schema ??= StringSchema::create(...);
 *         }
 *     }
 *
 * **Input is JSON decoded to associative arrays** – `json_decode($json, true)`.
 *
 * **Validation happens on the one path that produces an instance**, which is what makes a value object valid
 * however it was built: {@see self::instanciate()} validates the whole payload against the class's own schema
 * before constructing anything, so that one bad request reports every issue in it at once instead of the first one
 * as an exception. A leaf is therefore validated once per level of nesting above it — accepted deliberately,
 * because the invariant holding everywhere is worth more than the saving.
 *
 * **Construction is the constructor, always.** Whatever a class calls its own way in, the engine builds it by
 * calling `__construct()` with the validated primitives — see {@see Instantiator}. There is no closure to hand
 * over, no naming convention to satisfy, and no second entry point for a class that keeps its constructor
 * private: opting in is implementing {@see ProvidesSchema}, which is a decision the class makes once, in its own
 * body, and which the engine can actually check.
 */
final class Schematic
{
    private function __construct() {}

    /**
     * Builds an instance, validating the input against the class's own schema first.
     *
     * Returns a result rather than throwing: every issue in the payload at once, with its paths, which is what a
     * caller handling input from outside wants. A named constructor, which has no half-built value object to hand
     * back, reads the same result with {@see InstanciationResult::valueOrThrow()}.
     *
     * Safe to call from the class's own named constructor.
     *
     * @template T of ProvidesSchema
     * @param class-string<T> $className
     * @return InstanciationResult<T>
     */
    #[\NoDiscard('inspect the InstanciationResult; discarding it drops the instanciation outcome')]
    public static function instanciate(string $className, mixed $input): InstanciationResult
    {
        $schema = $className::schema();
        $result = $schema->validate($input);
        if (!$result->valid) {
            return InstanciationResult::failed($className, $result->issues);
        }
        return InstanciationResult::built(Instantiator::build($className, $input, $schema));
    }

    /**
     * The way back out: an instance read into the primitives its schema describes, ready for `json_encode()`.
     *
     * **The schema is required**, and it is what makes this the exact inverse of {@see self::instanciate()}. The
     * class shape drives *extraction* — it alone knows an enum's backing value, a scalar value object's single
     * member, a collection's `Traversable` — but PHP's one `array` type covers two JSON types, so it cannot say
     * whether `[]` is an empty list or an empty map. Only the schema can, and saying so is the one thing it is
     * consulted for out here — see {@see Serializer}.
     *
     * @throws UnextractableValue if the instance does not expose the state its constructor names
     */
    #[\NoDiscard('inspect the returned primitives; discarding them means the serialization was pointless')]
    public static function serialize(Schema $schema, mixed $value): mixed
    {
        return Serializer::read($value, $schema);
    }
}
