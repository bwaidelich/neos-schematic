<?php

declare(strict_types=1);

namespace Neos\Schematic;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;
use Neos\Schematic\Discovery\CircularReferenceException;
use Neos\Schematic\Discovery\UnsupportedTypeException;
use Neos\Schematic\Exception\UnextractableValue;
use Neos\Schematic\Instantiation\EnumCaseInstantiator;
use Neos\Schematic\Instantiation\Instantiator;
use Neos\Schematic\Instantiation\ListOfItemsInstantiator;
use Neos\Schematic\Instantiation\NamedShapeInstantiator;
use Neos\Schematic\Instantiation\ScalarValueInstantiator;
use Neos\Schematic\Instantiation\UnbuildableInstantiator;
use Neos\Schematic\Reflection\ClassShape;
use Neos\Schematic\Serialization\Serializer;

/**
 * The bridge between PHP classes and JSON Schema — and nothing else. A type owns its schema by returning it from a
 * static `schema()`; a plain record that does not is described by {@see AutoDiscoveringSchema} instead — see
 * {@see self::schemaOf()} for when. There is no registry, no container and nothing to wire up, so every entry point
 * here is static:
 *
 *     final readonly class Slug implements ProvidesSchema
 *     {
 *         private function __construct(public string $value) {}
 *
 *         public static function fromString(string $value): self
 *         {
 *             return Schematic::instantiate(self::class, $value)->valueOrThrow();
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
 * however it was built: {@see self::instantiate()} validates the whole payload against the class's own schema
 * before constructing anything, so that one bad request reports every issue in it at once instead of the first one
 * as an exception. A leaf is validated *there*, in that one pass, because a class's schema embeds the schemas of
 * everything it contains — so the engine below this line builds and never judges. The one thing that escapes it is
 * a hand-written `schema()` describing a member more loosely than that member's own schema does: nothing re-checks
 * it, and its constructor is the last word. A derived schema cannot say that.
 *
 * **Construction is the constructor, always.** Whatever a class calls its own way in, the engine builds it by
 * calling `__construct()` with the validated primitives — see {@see Instantiator}. There is no closure to hand
 * over, no naming convention to satisfy, and no second entry point for a class that keeps its constructor
 * private: a class with a private constructor opts in by implementing {@see ProvidesSchema}, which is a decision
 * it makes once, in its own body, and which the engine can actually check. A class whose constructor is public
 * needs no such decision — anyone may call it already, and the engine calling it bypasses nothing.
 */
final class Schematic
{
    /**
     * Schemas derived on a class's behalf, by class name. A class owning its schema is asked every time — memoizing
     * that one is its own business.
     *
     * @var array<class-string, Schema>
     */
    private static array $derived = [];

    private function __construct() {}

    /**
     * The schema a class is validated against, built by and serialized under:
     *
     * - its own `schema()` if it implements {@see ProvidesSchema},
     * - otherwise, if anyone may call its constructor (it is public, absent, or the class is an enum), the schema
     *   {@see AutoDiscoveringSchema} derives from it — once per class and process, since that depends on nothing
     *   but the class,
     * - otherwise nothing: a private constructor guards a way in of its own that analysis cannot see.
     *
     * A custom {@see Instantiator} link asks this, too, rather than calling `schema()` itself.
     *
     * @param class-string $className
     * @throws UnsupportedTypeException|CircularReferenceException if the class cannot be described — including a
     *     class that provides no schema and whose constructor is not public; a programming error, never a reaction
     *     to input
     */
    public static function schemaOf(string $className): Schema
    {
        if (is_a($className, ProvidesSchema::class, true)) {
            return $className::schema();
        }
        if (isset(self::$derived[$className])) {
            return self::$derived[$className];
        }
        if (!ClassShape::of($className)->publiclyConstructible) {
            throw UnsupportedTypeException::notDescribable($className);
        }
        return self::$derived[$className] = AutoDiscoveringSchema::analyze($className);
    }

    /**
     * Builds an instance, validating the input against the class's schema first — see {@see self::schemaOf()}.
     *
     * Returns a result rather than throwing: every issue in the payload at once, with its paths, which is what a
     * caller handling input from outside wants. A named constructor, which has no half-built value object to hand
     * back, reads the same result with {@see InstantiationResult::valueOrThrow()}.
     *
     * Safe to call from the class's own named constructor.
     *
     * @template T of object
     * @param class-string<T> $className
     * @return InstantiationResult<T>
     */
    #[\NoDiscard('inspect the InstantiationResult; discarding it drops the instantiation outcome')]
    public static function instantiate(string $className, mixed $input): InstantiationResult
    {
        $result = self::schemaOf($className)->validate($input);
        if (!$result->valid) {
            return InstantiationResult::failed($className, $result->issues);
        }
        // the chain, in the order it is asked: the specific kinds first, the general shape last, and a refusal
        // for what none of them claimed. A client wanting a kind of its own builds this chain with a link of its
        // own in front and calls it directly — there is nothing here for it to register with.
        $instantiator = new EnumCaseInstantiator(
            new ScalarValueInstantiator(
                new ListOfItemsInstantiator(
                    new NamedShapeInstantiator(
                        new UnbuildableInstantiator(),
                    ),
                ),
            ),
        );
        return InstantiationResult::built($instantiator->build($className, $input, $instantiator));
    }

    /**
     * The way back out: an instance read into the primitives its schema describes ({@see self::schemaOf()}), ready
     * for `json_encode()`.
     *
     * @throws UnextractableValue if the instance does not expose the state its constructor names
     */
    #[\NoDiscard('inspect the returned primitives; discarding them means the serialization was pointless')]
    public static function serialize(object $value): mixed
    {
        return Serializer::serialize($value, self::schemaOf($value::class));
    }
}
