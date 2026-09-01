# Schematic

> [!WARNING]
> At this stage, this package is **experimental** and subject to change!

Build validated PHP value objects out of JSON payloads, and serialize them back to JSON. Backed
by [JSON Schema](https://json-schema.org/) inside the types.

Schematic is **a bridge between PHP classes and JSON Schema**. A type owns its schema by implementing
`Neos\JsonSchema\ProvidesSchema` (returning it from a static `schema()`).

<!-- TOC -->
* [Schematic](#schematic)
  * [Installation](#installation)
  * [Quick start](#quick-start)
  * [AutoDiscoveringSchema](#autodiscoveringschema)
  * [Instantiation of objects](#instantiation-of-objects)
    * [why we recommend private constructors](#why-we-recommend-private-constructors)
    * [Recommended: Classes validating themselves via static `fromXY` methods](#recommended-classes-validating-themselves-via-static-fromxy-methods)
    * [Custom instantiation with Instantiator Chains](#custom-instantiation-with-instantiator-chains)
  * [Serialization of objects](#serialization-of-objects)
  * [Known limitations](#known-limitations)
  * [Contribution](#contribution)
<!-- TOC -->

## Installation

```bash
composer require neos/schematic
```

## Quick start

A value object owns its schema and hands over its own construction path; a shape derives one from its constructor:

```php
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;
use Neos\Schematic\Schematic;

final readonly class Slug implements ProvidesSchema
{
    // we suggest a PRIVATE constructor because this way, the value is already validated.
    private function __construct(public string $value) {}

    // OPTIONAL:
    // We suggest that the validation happens in the static factory method,
    // using the existing schema.
    public static function fromString(string $value): self
    {
        self::schema()->validate($value)->throwIfInvalid();
        return new self($value);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(minLength: 1, maxLength: 100, pattern: '^[a-z0-9-]+$');
    }
}

final readonly class Post implements ProvidesSchema
{
    // both private and public constructors are supported here.
    private function __construct(
        public Slug $slug,
        public string $author,
        public string $text,
    ) {}

    public static function schema(): Schema
    {
        static $schema = null;
        // AutoDiscoveringSchema builds up the schema by reading out class properties (recursively).
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}

$result = Schematic::instantiate(Post::class, ['slug' => 'hello-world', 'author' => 'Ada', 'text' => 'Hi']);

assert($result->success === true);
assert($result->value() instanceof Post);
assert($result->value()->slug->value === 'hello-world');

// and back out again — the same shape the schema describes
assert(json_encode(Schematic::serialize($result->value()))
    === '{"slug":"hello-world","author":"Ada","text":"Hi"}');
```

The `static $schema` is used for in-memory caching. A schema is class-level and immutable, so building it once is a good
idea. This way, this package contains no caching or any other state at all.

**Input is JSON decoded to associative arrays** — `json_decode($json, true)`. A `\stdClass` is reported as the wrong
type rather than unwrapped, so a payload that validates is a payload that builds.

## AutoDiscoveringSchema

`AutoDiscoveringSchema::analyze()` reads a class's **constructor** and emits pure JSON Schema. What a class *is*
follows from its parameters, and that same reading drives building and serializing:

| Class                                          | Schema                                                         | In                             | Out                      |
|------------------------------------------------|----------------------------------------------------------------|--------------------------------|--------------------------|
| an `enum`                                      | `string`/`integer` with `enum:` of backing values or names     | `tryFrom()` / case-name lookup | `->value` / `->name`     |
| one variadic parameter                         | `array` whose `items` is that parameter's type                 | build each item, spread        | iterate the property     |
| one parameter typed `string\|int\|float\|bool` | the scalar schema of that parameter                            | pass the primitive             | read the single property |
| anything else                                  | `object`, `additionalProperties: false`, required = no default | map keys to parameters by name | read each named property |

Extras:

- A nested type implementing `ProvidesSchema` contributes *its own* `schema()`
- `?T` adds a null branch
- `A|B` (Union Types): becomes an `anyOf` over them.

```php
// ... 
enum Status: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
}

final readonly class Draft implements ProvidesSchema
{
    public function __construct(
        public Status $status,
        public string $title,
        public string|null $subtitle = null,
    ) {}

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}

assert(json_encode(Draft::schema()) === '{"type":"object","properties":'
    . '{"status":{"type":"string","enum":["draft","published"]},"title":{"type":"string"},'
    . '"subtitle":{"anyOf":[{"type":"string"},{"type":"null"}]}},'
    . '"additionalProperties":false,"required":["status","title"]}');
```

## Instantiation of objects

`Schematic::instantiate()` validates first using the JSON schema of the target class, then builds the object.

* if the payload is valid: an `InstantiationResult` carrying the instance as `->value()`;
* if it is invalid: the same result with `->success === false`, and every issue in the payload at once, each at its own path.

```php
// ... Slug and Post from the quick start
use Neos\Schematic\Schematic;

$result = Schematic::instantiate(Post::class, ['slug' => 'Hello World', 'author' => 'Ada']);

assert($result->success === false);
assert(array_map(fn($issue) => $issue->pathAsString(), $result->issues->toArray()) === ['slug', 'text']);
assert($result->issues->codes() === ['invalid_pattern', 'required']);
```

A caller with no use for a failed outcome reads that same result with `->valueOrThrow()`, and gets a `CoercionFailed`
carrying those issues.

One consequence of validating once: a **hand-written** `schema()` describing a member more loosely than the member's own
schema does (an `additionalProperties: true` object where a `Slug` really goes) leaves that member unchecked against
`Slug::schema()`, with its constructor as the last word. `AutoDiscoveringSchema` cannot produce such a schema.

### why we recommend private constructors

A public constructor can not enforce the schema. As the invariant has to hold for *every* instance, we **recommend to 
make the constructor private**.

A class implementing `ProvidesSchema` has its `__construct()` called with the validated primitives.

### Recommended: Classes validating themselves via static `fromXY` methods

A named static factory method is how another instantiator can create instances. There we recommend
to use the schema for validation before construction:

```php
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Validation\ValidationFailedException;

final readonly class Handle implements ProvidesSchema
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        self::schema()->validate($value)->throwIfInvalid();
        return new self($value);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(pattern: '^[a-z]+$');
    }
}

assert(Handle::fromString('ada')->value === 'ada');

$caught = null;
try {
    Handle::fromString('Ada');
} catch (ValidationFailedException $exception) {
    $caught = $exception->issues->codes();
}
assert($caught === ['invalid_pattern']);
```

Remember, **Schematic never calls `from*()` methods.** It constructs directly, so a named constructor going through Schematic is not a loop.

### Custom instantiation with Instantiator Chains

Instantiation runs through a chain of `Instantiator` objects, each handling one kind of data, and passing on everything else
to the next instantiator.

```php (no test)
interface Instantiator
{
    /**
     * @template T of ProvidesSchema
     * @param class-string<T> $className
     * @return T
     */
    public function build(string $className, mixed $value, Instantiator $root): object;
}
```

`Schematic::instantiate()` wires the built-in Instantiators together:

```php (no test)
new EnumCaseInstantiator(
    new ScalarValueInstantiator(
        new ListOfItemsInstantiator(
            new NamedShapeInstantiator(
                new UnbuildableInstantiator(),
            ),
        ),
    ),
)
```

- the most specific instantiators should be at the start of the chain; the most generic more towards the end.
- at the very end, `UnbuildableInstantiator` throws an error.

- `$root` must be the whole chain, handed to every link, so descending into a member
  can start at the full chain again.

**When you would write one.** A schema with no single structural reading is the usual reason: a `oneOf` whose branches
are different kinds of thing cannot say what to build, so the built-in chain refuses it:

```php
use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\OneOfSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;
use Neos\Schematic\Instantiation\EnumCaseInstantiator;
use Neos\Schematic\Instantiation\Instantiator;
use Neos\Schematic\Instantiation\ListOfItemsInstantiator;
use Neos\Schematic\Instantiation\NamedShapeInstantiator;
use Neos\Schematic\Instantiation\ScalarValueInstantiator;
use Neos\Schematic\Instantiation\UnbuildableInstantiator;
use Neos\Schematic\Schematic;

/**
 * A reference that travels either as a bare id or as an object carrying one.
 */
final readonly class Reference implements ProvidesSchema
{
    public function __construct(public string $id) {}

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= OneOfSchema::create(
            StringSchema::create(minLength: 1),
            ObjectSchema::create(
                properties: ObjectProperties::create(id: StringSchema::create(minLength: 1)),
                required: ['id'],
            ),
        );
    }
}

$caught = null;
try {
    $unreached = Schematic::instantiate(Reference::class, 'dune');
} catch (\LogicException $exception) {
    $caught = $exception->getMessage();
}
assert(str_contains((string) $caught, 'could not be built by any Instantiator'));


final readonly class BuildReference implements Instantiator
{
    public function __construct(private Instantiator $next) {}

    public function build(string $className, mixed $value, Instantiator $root): object
    {
        if ($className !== Reference::class) {
            return $this->next->build($className, $value, $root);
        }
        return new Reference(is_string($value) ? $value : $value['id']);
    }
}

$instantiator = new BuildReference(
    new EnumCaseInstantiator(
        new ScalarValueInstantiator(
            new ListOfItemsInstantiator(
                new NamedShapeInstantiator(
                    new UnbuildableInstantiator(),
                ),
            ),
        ),
    ),
);

// the engine builds and never judges, so validating is yours — once, against the schema the class owns
$input = ['id' => 'dune'];
Reference::schema()->validate($input)->throwIfInvalid();

$reference = $instantiator->build(Reference::class, $input, $instantiator);
assert($reference->id === 'dune');
```

Because every link is handed `$root`, the same wiring reaches a `Reference` sitting anywhere inside something else:

```php
// ... Reference and BuildReference from above
final readonly class Edition implements ProvidesSchema
{
    public function __construct(public Reference $of, public int $year) {}

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            properties: ObjectProperties::create(of: Reference::schema(), year: IntegerSchema::create()),
            required: ['of', 'year'],
        );
    }
}

$payload = ['of' => 'dune', 'year' => 1965];
Edition::schema()->validate($payload)->throwIfInvalid();

$edition = $instantiator->build(Edition::class, $payload, $instantiator);
assert($edition->of->id === 'dune');
assert($edition->year === 1965);
```

`NamedShapeInstantiator` built the `Edition`, and descending into its `of` member went back to the top of the chain, where
`BuildReference` claimed it.

`Schematic::instantiate()` takes no chain, for now, because we did not figure out yet how we make this extensible in
a good way.

## Serialization of objects

`Schematic::serialize()` takes a value whose class owns a schema — the same thing `instantiate()` takes, which is what
makes it the exact inverse. The class drives *extraction*: only it knows an enum's backing value, a value object's
single member, a collection's items. But PHP's one `array` type covers two JSON structures, so `[]` alone cannot say
whether it was an empty map or an empty list. The schema can, and that is the one thing it is asked about a *value*
out here:

```php
use Neos\JsonSchema\ArraySchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;
use Neos\Schematic\Schematic;

final readonly class Registry implements ProvidesSchema
{
    /**
     * @param array<string, mixed> $entries
     * @param list<string> $names
     */
    public function __construct(public array $entries, public array $names) {}

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            properties: ObjectProperties::create(
                entries: ObjectSchema::create(additionalProperties: true),
                names: ArraySchema::create(items: StringSchema::create()),
            ),
            required: ['entries', 'names'],
        );
    }
}

$registry = Schematic::instantiate(Registry::class, ['entries' => [], 'names' => []])->value();

// the empty map is a map and the empty list is a list, because the schema says which is which
assert(json_encode(Schematic::serialize($registry)) === '{"entries":{},"names":[]}');
```

A value read under a schema that is **not** its own class's needs to call a lower-level API:
`Serializer::serialize($value, $schema)`.

A class emitting a member its schema does *not* describe is raised as an `UnextractableValue`, as an undocumented
field going out is something no client could ever report.

Extraction reads **public properties only**.

## Known limitations

- **Constructor parameters must be named types, or a union of them.** Intersections are rejected. `mixed` and a bare
  `null` are not supported either — they report as nullable to reflection but describe no shape.
- **Recursive types are rejected**, with a `CircularReferenceException` naming the cycle. A schema embeds its children
  directly, so it cannot represent one.
- **A union-typed constructor parameter is built by trying its branches.** They are tried in the order they are
  declared — the order the published `anyOf` lists them — and the first branch whose schema accepts the value is the
  one it gets built as. Overlapping branches therefore resolve to the first, not the best, match.
- **Tag-discriminated polymorphism is not built in.** A union member is resolved by *trying* its branches, which is what
  `anyOf` means; a payload whose type is decided by one of its own keys is not that. Reading the tag is a rule only the
  consumer knows, so it belongs in a link of its own — see
  [Custom instantiation with Instantiator Chains](#custom-instantiation-with-instantiator-chains).
- **Serialization reads public properties.** A class with private state writes its own `jsonSerialize()`.

## License

MIT
