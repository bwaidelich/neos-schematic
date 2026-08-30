# Schematic

> [!WARNING]
> At this stage, this package is **experimental** and subject to change!

Build validated PHP value objects out of unknown input — JSON payloads, form data, database rows — and read them back
out again, against [JSON Schema](https://json-schema.org/) the types themselves own.

Schematic is **a bridge between PHP classes and JSON Schema**. A type owns its schema by implementing
`Neos\JsonSchema\ProvidesSchema` (returning it from a static `schema()`).

## Requirements

PHP 8.4 or higher.

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
    public function __construct(public string $value) {}

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= StringSchema::create(minLength: 1, maxLength: 100, pattern: '^[a-z0-9-]+$');
    }
}

final readonly class Post implements \JsonSerializable, ProvidesSchema
{
    public function __construct(
        public Slug $slug,
        public string $author,
        public string $text,
    ) {}

    public function jsonSerialize(): mixed
    {
        return Schematic::serialize(self::schema(), $this);
    }

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= AutoDiscoveringSchema::analyze(self::class);
    }
}

$result = Schematic::map(Post::class, ['slug' => 'hello-world', 'author' => 'Ada', 'text' => 'Hi']);

assert($result->valid === true);
assert($result->value() instanceof Post);
assert($result->value()->slug->value === 'hello-world');

// and back out again — the same shape the schema describes
assert(json_encode($result->value()) === '{"slug":"hello-world","author":"Ada","text":"Hi"}');
```

Both constructors are public and the engine calls them itself — there is nothing else to wire up. `Slug` implements
`ProvidesSchema` from **neos/jsonschema**, so any package can read its type without depending on this one.

The `static $schema` is the whole of caching. A schema is class-level and immutable, so building it once is worth
doing — and **this package holds no state on a class's behalf**: no registry, no schema cache, nothing to reset
between requests or tests. A class that leaves the line out is asking for its schema to be rebuilt on every call,
once per level of nesting, on every request — which for `AutoDiscoveringSchema::analyze()` means the whole reflection
walk again. (The examples further down leave it out where they are making a different point.)

### Owning a schema is opt-in

A class is described because it said so, never because it happened to look describable. Asking for the schema of a class
that implements nothing is an error:

```php
// ... Slug and Post from the quick start
use Neos\Schematic\SchemaNotProvided;
use Neos\Schematic\Schematic;

final readonly class Untold
{
    public function __construct(public string $value) {}
}

$caught = null;
try {
    Schematic::schemaFor(Untold::class);
} catch (SchemaNotProvided $exception) {
    $caught = $exception->getMessage();
}
assert(str_contains((string) $caught, 'provides no schema: implement'));
```

Deriving one silently would make the published contract an accident of the constructor — every parameter rename a change
to it — and would describe classes that were never meant to cross a wire. A class with nothing special to say still
fills the method in mechanically, with `AutoDiscoveringSchema::analyze(self::class)` as `Post` does above; the
difference is that it asked. From there analysis recurses into nested types on its own, so only the types a consumer
*names* need the method.

## The four entry points

| Call                                                                   | What it does                                         |
|------------------------------------------------------------------------|------------------------------------------------------|
| `Schematic::schemaFor($class)`                                         | the class's own `schema()` — an error if it has none |
| `Schematic::instantiate($class, $input, $normalization?)`              | validate, then build — **throws** `CoercionFailed`   |
| `Schematic::map($class, $input, $normalization?)`                      | validate, then build — returns a `ValidationResult`  |
| `Schematic::serialize($schema, $value)`                                | read an instance back into primitives                |

`instantiate()` throws because it is what a named constructor calls: there is no half-built value object to hand back.
`map()` is for a payload arriving from outside, where every issue in it is wanted at once.

## Nothing is built without being validated first

Every entry point validates against the class's own schema before it constructs anything — `map()` reports what is
wrong, `instantiate()` throws:

```php
// ... Slug and Post from the quick start
use Neos\Schematic\CoercionFailed;
use Neos\Schematic\Schematic;

$slug = Schematic::instantiate(Slug::class, 'hello-world');
assert($slug->value === 'hello-world');

$caught = null;
try {
    Schematic::instantiate(Slug::class, 'Hello World');
} catch (CoercionFailed $exception) {
    $caught = $exception->issues->codes();
}
assert($caught === ['invalid_pattern']);
```

The `Slug` inside a `Post` goes through the very same check, because the engine descends into it the same way — there
is no second, unvalidated path through the engine.

This means a leaf is validated once per level of nesting above it. That is deliberate: at the usual
one-level-of-value-objects depth it is two passes, and the invariant holding everywhere is worth more than the saving.

A public constructor is still a public constructor, though: `new Slug('Hello World')` is plain PHP and nothing checks
it. If the invariant has to hold for *every* construction, make the constructor private and offer a named constructor
that goes through `Schematic::instantiate()` — see below.

### Why the outer pass exists at all

Because `Post::schema()` *embeds* `Slug::schema()`, a whole payload can be judged in one go — which is what lets a bad
request report everything wrong with it, each issue where it belongs:

```php
// ... Slug and Post from the quick start
use Neos\Schematic\Schematic;

$result = Schematic::map(Post::class, ['slug' => 'Hello World', 'author' => 'Ada']);

assert($result->valid === false);
assert(array_map(fn($issue) => $issue->pathAsString(), $result->issues->toArray()) === ['slug', 'text']);
assert($result->issues->codes() === ['invalid_pattern', 'required']);
```

## Construction: the constructor, whatever its visibility

A class opts into being built by implementing `ProvidesSchema`. From there the engine calls its `__construct()` with
the validated primitives — a public constructor directly, a non-public one through reflection. There is nothing else to
hand over:

```php
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;

final readonly class Handle implements ProvidesSchema
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        return Schematic::instantiate(self::class, $value);
    }

    public static function schema(): Schema
    {
        return StringSchema::create(pattern: '^[a-z]+$');
    }
}

assert(Handle::fromString('ada')->value === 'ada');
assert(Schematic::instantiate(Handle::class, 'ada')->value === 'ada');
```

`private` is a fence against `new Handle(...)` from anywhere else, and it stays one; it is not a fence against the
engine the class invited by owning a schema. That opt-in is a decision the class makes once, in its own body, and one
the engine can actually check — unlike a "call this only from inside the class" rule nothing enforces.

Shapes are built with **named arguments**, so parameter order does not matter; scalars get the value, collections get
the items spread.

### How the engine reaches a nested type

For a value of class `T`, in order:

1. `T` is an enum → the case is looked up, nothing is constructed;
2. otherwise → `T::__construct()` is called with the validated primitives, public or not.

**Named constructors are never called by the engine.** A `from*()` method is how a *caller* writes the class's way in,
and it calls `Schematic::instantiate()` itself — so calling it from the engine would validate the same value twice per
level of nesting, and turn the obvious `fromString()` into unbounded recursion.

The consequence, and it is the intended trade: **a rule living in a named constructor body, rather than in the schema
or the constructor, is not enforced on the way in.** That is the same rule the package states for invariants generally
— the schema is the validator, the constructor is the last word.

## What a class looks like to the engine

Both building and reading back need **no schema at all**: after validation the value already *is* schema-conformant
primitives, so what remains is the class's own shape. It is decided from the class alone:

| Class                                          | Nature | In                                             | Out                                       |
|------------------------------------------------|--------|------------------------------------------------|-------------------------------------------|
| an `enum`                                      | Enum   | `tryFrom()` (backed) / case-name lookup (pure) | `->value` / `->name`                      |
| one variadic constructor parameter             | ListOf | build each item, spread                        | iterate, or read the property             |
| one parameter typed `string\|int\|float\|bool` | Scalar | pass the primitive                             | read the single public property           |
| anything else                                  | Shape  | map array keys to parameters by name           | read each parameter-named public property |

Unambiguous by construction: `Slug{string $value}` is a Scalar, `Tags{Tag ...$tags}` a ListOf,
`Point{array $coords}` a Shape expecting `['coords' => …]`, and `Wrapper{Inner $inner}` a Shape. A value object wanting
collection semantics makes its parameter variadic.

```php
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;
use Neos\Schematic\Reflection\ClassShape;
use Neos\Schematic\Reflection\Nature;
use Neos\Schematic\Schematic;

final readonly class Tag implements ProvidesSchema
{
    public function __construct(public string $value) {}

    public static function schema(): Schema
    {
        return AutoDiscoveringSchema::analyze(self::class);
    }
}

final readonly class Tags implements ProvidesSchema
{
    /** @var list<Tag> */
    public array $tags;

    public function __construct(Tag ...$tags)
    {
        $this->tags = array_values($tags);
    }

    public static function schema(): Schema
    {
        return AutoDiscoveringSchema::analyze(self::class);
    }
}

assert(ClassShape::of(Tag::class)->nature === Nature::Scalar);
assert(ClassShape::of(Tags::class)->nature === Nature::ListOf);

$tags = Schematic::instantiate(Tags::class, ['php', 'json']);
assert(Schematic::serialize(Tags::schema(), $tags) === ['php', 'json']);
```

## Deriving a schema from the class

`AutoDiscoveringSchema::analyze()` reads a class and emits **pure** JSON Schema, following the same natures:

| Nature | Schema                                                                                              |
|--------|-----------------------------------------------------------------------------------------------------|
| Enum   | `string` / `integer` with `enum:` of the backing values, or the case names                          |
| Scalar | the scalar schema of its single parameter                                                           |
| ListOf | an `array` whose `items` is the variadic parameter's type                                           |
| Shape  | an `object` of its parameters, `additionalProperties: false`, required = the ones without a default |

A nested type that implements `ProvidesSchema` contributes *its own* `schema()` verbatim; anything else is analyzed in
turn. `?T` / `T|null` gains a null branch:

```php
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

enum Status: string
{
    case draft = 'draft';
    case published = 'published';
}

final readonly class Draft
{
    public function __construct(
        public Status $status,
        public string $title,
        public string|null $subtitle = null,
    ) {}
}

assert(json_encode(AutoDiscoveringSchema::analyze(Draft::class)) === '{"type":"object","properties":'
    . '{"status":{"type":"string","enum":["draft","published"]},"title":{"type":"string"},'
    . '"subtitle":{"anyOf":[{"type":"string"},{"type":"null"}]}},'
    . '"additionalProperties":false,"required":["status","title"]}');
```

There are no attributes to read, and no `#[Description]`: a constraint that does not follow from the type belongs in the
class's own `schema()`, which is the point — the published schema and the enforced rule are one object.

Analysis rejects what it cannot describe with an `UnsupportedTypeException`: an enum with no cases, a genuine union or
intersection type, `mixed`, and builtins that are not one of the four JSON scalars. A recursive type raises a
`CircularReferenceException` naming the cycle, since an embedded schema cannot contain itself.

## Input that is always a string

Validation is standard JSON Schema by default, so `"42"` is a string. A path, query or header parameter is only ever a
string, so those call sites opt into `Normalization::Scalars`:

```php
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Validation\Normalization;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;
use Neos\Schematic\Schematic;

enum Rating: int implements ProvidesSchema
{
    case low = 1;
    case high = 5;

    public static function schema(): Schema
    {
        return AutoDiscoveringSchema::analyze(self::class);
    }
}

assert(Schematic::map(Rating::class, '5')->valid === false);                                  // a JSON body
assert(Schematic::instantiate(Rating::class, '5', normalization: Normalization::Scalars) === Rating::high);
```

## Serializing back out

`Schematic::serialize()` takes **the schema as well as the value**, and that is what makes it the exact inverse of
`map()`. The class shape drives *extraction* — only the class knows an enum's backing value, a value object's single
member, a collection's `Traversable`. But PHP has one `array` type where JSON has two, so the class cannot say
whether `[]` was an empty list or an empty map, and a schema-free serializer emits `[]` for both — including where
the very schema it published says the member is an object. The schema is the only thing that can answer, which is
exactly how validation already reads `[]` on the way *in*:

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

$registry = Schematic::map(Registry::class, ['entries' => new \stdClass(), 'names' => []])->value();

// the empty map is a map and the empty list is a list, because the schema says which is which
assert(json_encode(Schematic::serialize(Registry::schema(), $registry)) === '{"entries":{},"names":[]}');
```

There is deliberately no overload deriving the schema from `$value::class`. A consumer may be serializing through a
*declared* type rather than the runtime class — an API describing its 200 response by the return type its document
publishes — and reaching for the runtime class behind its back would quietly undo that. Where the runtime class *is*
the right answer, it is one line: `Schematic::serialize(Schematic::schemaFor($value::class), $value)`.

Members the schema does not name are handed back untouched: a free-form map's values are described by nothing, so
there is nothing there to shape and guessing would be the mistake this avoids. Members it does name go out in the
order it names them, and keys it does not publish follow — a class emitting what its document does not describe is
a disagreement for `Conformance` to report, not one to hide by dropping.

Extraction reads **public properties only**. Going in, a class exposes itself to the engine by owning a schema, and
its constructor names exactly what it takes; going out, there is no such statement — a property is either public or
it is not. So a class with private state exposes it, or writes a `jsonSerialize()` of its own:

```php
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Schematic;
use Neos\Schematic\UnextractableValue;

final readonly class Secret
{
    public function __construct(private string $value) {}

    public function reveal(): string
    {
        return $this->value;
    }
}

$caught = null;
try {
    Schematic::serialize(StringSchema::create(), new Secret('hunter2'));
} catch (UnextractableValue $exception) {
    $caught = $exception->getMessage();
}
assert(str_contains((string) $caught, 'does not expose a public property "$value"'));
```

A class that wants `json_encode($post)` to emit exactly what its schema describes writes the one line itself:

```php (no test)
public function jsonSerialize(): mixed
{
    return Schematic::serialize(self::schema(), $this);
}
```

There is no trait for it. Implementing `JsonSerializable` is a statement about the class, and a `use` line that
silently supplies the method hides which of the two ways out the class chose — this one, or a `jsonSerialize()` of
its own.

A collection is read through `Traversable` when it offers one, and otherwise from the public property named after its
variadic parameter. One that offers neither raises `UnextractableValue`: a class and the way it is read back disagreeing
is a programming error, not bad input.

## Making sure the class and its schema still agree

A derived schema cannot drift: `AutoDiscoveringSchema` reads it off the same `ClassShape` the instantiator builds by
and the serializer reads by, so nullability, requiredness and structure come from one source. A **hand-written**
`schema()` can drift, and every way it can is invisible until it bites — validation passes, then construction fails
with a `TypeError` for a null the constructor never accepted, or a `LogicException` for a parameter the schema forgot
to require. `Conformance` asks, from the class side:

```php
use Neos\JsonSchema\Nullable;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;
use Neos\Schematic\Conformance;

final readonly class Article implements ProvidesSchema
{
    public function __construct(public string $title, public string $subtitle) {}

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            properties: ObjectProperties::create(
                title: StringSchema::create(),
                subtitle: Nullable::wrap(StringSchema::create()),   // the constructor says otherwise
            ),
            additionalProperties: false,
            required: ['title'],                                    // and $subtitle has no default
        );
    }
}

assert(Conformance::check(Article::class) === [
    '$subtitle: the constructor has no default for it, but the schema does not require it',
    '$subtitle: the schema accepts null, the constructor does not',
]);
```

It is **not** a runtime guard: the answer is the same for the life of the process, so it belongs in a test —
`self::assertSame([], Conformance::check(Article::class))` — where a disagreement is what it actually is, a
programming error, rather than a cost paid on every request. Every question is put to the schema by *validating*
against it rather than by reading its keywords, so a hand-written `anyOf`, a `oneOf` or anything else answers exactly
as the validator would.

What it compares:

| Question | Reported when |
|----------|---------------|
| nullability | the schema accepts `null` for a parameter that does not, or refuses one for a parameter that does |
| requiredness | a parameter has no default and the schema does not require it |
| structure | the class is a value and the schema a structure (or the reverse), or an array schema without items |
| naming | the constructor names a parameter the schema has no property for, or the schema publishes one nothing builds |
| type | a parameter's builtin type is one the schema rejects outright |
| enum cases | a case serializes to something the schema does not accept |

### The other half: what an instance emits

`check()` asks whether the class can be *built* from what its schema accepts. A public constructor means `new` can
produce a value validation never saw, and serialization publishes it as it finds it — so the second question needs an
instance:

```php (no test)
self::assertSame([], Conformance::checkSerialization($article));
```

That is the *whole* check for a type that is only ever emitted — one that publishes a schema for the document it
appears in and writes its own `jsonSerialize()`, with nothing ever mapping a payload onto it. Such a type answers the
constructor question meaninglessly; it is held to what it puts on the wire.

## Invariants the schema cannot express

Some rules span several properties — `start < end` — and no JSON Schema keyword covers them. **The schema is the only
validator**: a rule it does not express is not enforced on the way in, and a constructor rejecting a value that
validation accepted means the class and its schema disagree. That is a programming error, so it crashes rather than
joining the result as an issue:

```php
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;
use Neos\Schematic\Schematic;

final readonly class DateRange implements ProvidesSchema
{
    public function __construct(
        public int $start,
        public int $end,
    ) {
        if ($start >= $end) {
            throw new \InvalidArgumentException(sprintf('start (%d) must be less than end (%d)', $start, $end));
        }
    }

    public static function schema(): Schema
    {
        return AutoDiscoveringSchema::analyze(self::class);
    }
}

$caught = null;
try {
    Schematic::map(DateRange::class, ['start' => 10, 'end' => 5]);
} catch (\InvalidArgumentException $exception) {
    $caught = $exception->getMessage();
}
assert($caught === 'start (10) must be less than end (5)');
```

To have such a rule reported as bad *input*, it has to reach the schema: express it there, or validate before mapping.
Nothing downstream turns an exception back into an issue.

## Known limitations

- **Constructor parameters must be single named types.** Genuine unions and intersections are rejected; a **nullable**
  type (`?Foo`, `Foo|null`) is supported. `mixed` and a bare `null` are not — they report as nullable to reflection but
  describe no shape.
- **Recursive types are rejected**, with a `CircularReferenceException` naming the cycle. A schema embeds its children
  directly, so it cannot represent one. (`neos/jsonschema` ships a `ReferenceSchema`, so this may change.)
- **Polymorphism is not supported.** A payload whose type is decided by one of its own keys has no schema this package
  derives. A class wanting it writes a `schema()` returning `OneOfSchema`, and a named constructor of its own that
  switches on the tag — the engine builds only what a single constructor describes.
- **Serialization reads public properties.** A class with private state writes its own `jsonSerialize()`.

## Architecture

```
src/
  Schematic.php                          the four entry points
  CoercionFailed.php                     input did not satisfy the class's schema
  SchemaNotProvided.php                  the class owns no schema, and none is invented for it
  UnextractableValue.php                 the instance does not expose what its shape names
  Conformance.php                        does a class still agree with the schema it publishes?
  Reflection/ClassShape.php (+ Nature)   what a class is, and its constructor parameters
  Discovery/AutoDiscoveringSchema.php    a schema derived from the class, when a class asks for one
  Instantiation/Instantiator.php         primitives -> instance, no schema involved
  Serialization/Serializer.php           instance -> primitives, no schema involved
```

Everything about *data* lives in `neos/jsonschema`: `Schema::validate()` normalizes, validates and projects, and hands
back the value it read. Schematic adds reflection over PHP classes on either side of it, and nothing more.

## Contribution

Contributions in the form of [issues](https://github.com/neos/schematic/issues) or
[pull requests](https://github.com/neos/schematic/pulls) are highly appreciated.
