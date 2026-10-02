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
    * [Descriptions](#descriptions)
  * [Instantiation of objects](#instantiation-of-objects)
    * [why we recommend private constructors](#why-we-recommend-private-constructors)
    * [Recommended: Classes validating themselves via static `fromXY` methods](#recommended-classes-validating-themselves-via-static-fromxy-methods)
    * [Custom instantiation with Instantiator Chains](#custom-instantiation-with-instantiator-chains)
  * [Serialization of objects](#serialization-of-objects)
  * [Types you do not own](#types-you-do-not-own)
    * [A stand-in, when the constraint is shared](#a-stand-in-when-the-constraint-is-shared)
  * [Known limitations](#known-limitations)
  * [License](#license)
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

    // OPTIONAL public factory - not used by Schematic; only needed if you manually need to construct objects:
    //
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
    public function __construct(
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
- `#[Description]` on the class or a parameter becomes the schema's `description` (see [below](#descriptions)).

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

### Descriptions

A type cannot say what a member *means*, so `#[Description]` can: on a class (or enum) it describes the schema of
that type, on a constructor parameter the schema of that member. It is the one attribute `AutoDiscoveringSchema`
reads, because it only annotates — which values are accepted does not change.

A parameter's description replaces the one of its type, since it is the more specific of the two. A nullable member is
described on its substantive branch.

```php
// ...
use Neos\Schematic\Discovery\Description;

#[Description('A comment on a post')]
final readonly class Comment implements ProvidesSchema
{
    public function __construct(
        #[Description('What the commenter wrote, as plain text')]
        public string $text,
        #[Description('Set once the comment was approved by a moderator')]
        public Status|null $status = null,
    ) {}

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}

assert(json_encode(Comment::schema()) === '{"type":"object","description":"A comment on a post","properties":'
    . '{"text":{"type":"string","description":"What the commenter wrote, as plain text"},'
    . '"status":{"anyOf":[{"type":"string","description":"Set once the comment was approved by a moderator",'
    . '"enum":["draft","published"]},{"type":"null"}]}},'
    . '"additionalProperties":false,"required":["text"]}');
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

A caller with no use for a failed outcome reads that same result with `->valueOrThrow()`, and gets a `InstantiationFailed`
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

## Types you do not own

A type from another package cannot implement `ProvidesSchema`, and you may not want to wrap it either — the rest of
your code should keep dealing with the real type.

You can have that, because the engine reads **the constructor**, not the property list:

- discovery describes each *constructor parameter*'s type,
- instantiation builds each parameter's type and passes it in,
- serialization reads the public property **named after each parameter**.

So a parameter and the property it fills may be different types. The parameter is what the schema describes; the
property is the foreign type; the constructor converts between them. In the simplest case there is no extra class at
all — the parameter is the primitive, and the schema you write says what it must look like:

```php
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;
use Neos\Schematic\Schematic;

/**
 * The type you cannot change: no `schema()`, and the rule it enforces lives in a pattern nothing can read.
 */
final readonly class ForeignId
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        if (preg_match('/^[a-z0-9\-]{1,64}$/', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('Not an id: "%s"', $value));
        }
        return new self($value);
    }
}

final readonly class Entry implements ProvidesSchema
{
    /** the foreign type, which is what the rest of your code reads */
    public ForeignId $id;

    public function __construct(string $id, public string $label)
    {
        $this->id = ForeignId::fromString($id);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            properties: ObjectProperties::create(
                // the constraint the foreign type keeps where reflection cannot see it
                id: StringSchema::create(pattern: '^[a-z0-9\-]{1,64}$', examples: ['first-entry']),
                label: StringSchema::create(),
            ),
            additionalProperties: false,
            required: ['id', 'label'],
        );
    }
}

$entry = Schematic::instantiate(Entry::class, ['id' => 'first-entry', 'label' => 'First'])->valueOrThrow();

// built from primitives, and what arrives in the property is the foreign type
assert($entry->id instanceof ForeignId);
assert($entry->id->value === 'first-entry');

// and it goes back out as the primitive the schema describes, because the schema says the slot is a string and
// the foreign type is a single value holding one
assert(json_encode(Schematic::serialize($entry)) === '{"id":"first-entry","label":"First"}');

// a bad id is *reported* against the published schema, not thrown by the foreign constructor
assert(Schematic::instantiate(Entry::class, ['id' => 'NOT AN ID', 'label' => 'First'])->success === false);
```

That last assertion is the reason to write the pattern down rather than let the foreign type enforce it alone:
everything the published schema accepts reaches `ForeignId::fromString()`, and whatever *it* rejects comes back as an
exception instead of an issue.

### A stand-in, when the constraint is shared

Writing the schema by hand is fine for one class. When several classes carry the same foreign type — or when you want
[AutoDiscoveringSchema](#autodiscoveringschema) to keep deriving the whole thing — give the constraint a type of its
own. It needs to be nothing more than a value and a schema:

```php
// ... continues the example above
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

final readonly class ForeignIdApiWrapper implements ProvidesSchema
{
    public function __construct(public string $value) {}

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(pattern: '^[a-z0-9\-]{1,64}$', examples: ['first-entry']);
    }
}

final readonly class Tag implements ProvidesSchema
{
    public ForeignId $id;

    public function __construct(ForeignIdApiWrapper $id, public string $name)
    {
        $this->id = ForeignId::fromString($id->value);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}

$tag = Schematic::instantiate(Tag::class, ['id' => 'second-entry', 'name' => 'Second'])->valueOrThrow();

assert($tag->id instanceof ForeignId);
assert(json_encode(Schematic::serialize($tag)) === '{"id":"second-entry","name":"Second"}');
```

No conversion methods on the stand-in, and nothing builds it on the way out — serialization reads the property, sees a
single-value object, and emits its value under the schema the slot published.

Two variants worth knowing:

- **Take the foreign type in a named constructor.** The constructors above accept a primitive or a stand-in, so a
  caller holding a real `ForeignId` writes `$id->value`. Adding
  `public static function create(ForeignId $id, string $label): self` removes that from every call site, at the cost
  of repeating the parameter list once. Worth it when there are several call sites; not when there is one.
- **Keep the property primitive and convert on the way out.** A `public function id(): ForeignId` next to a plain
  `public string $id` costs one line instead of two and needs no constructor body — but consumers call a method, and
  the conversion runs on every read.

And three things to keep in mind:

- **The property must carry the same name as the parameter.** Serialization looks the member up by the parameter's
  name, and a mismatch is an `UnextractableValue` telling you so.
- **The foreign type has to expose the state going out.** Serialization reads public properties. If it exposes none,
  keep the stand-in in the property and add a method returning the foreign type.
- **A stand-in is a type your documentation will name**, since it is the type the constructor declares. A name like
  `ForeignIdApiWrapper` says exactly what it is to a reader of the code, and a generated document will then carry that
  name too — so pick whichever of the two audiences matters more, and pick it deliberately. Taking the primitive, as
  in the first example, publishes nothing extra at all.

## Known limitations

- **Constructor parameters must be named types, or a union of them.** Intersections are rejected. `mixed` and a bare
  `null` are not supported either — they report as nullable to reflection but describe no shape.
- **A union-typed parameter cannot carry a `#[Description]`.** An `anyOf` has no `description` of its own, so
  analysis rejects it rather than drop the description silently.
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
