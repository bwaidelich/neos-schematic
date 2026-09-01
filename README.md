# Schematic

> [!WARNING]
> At this stage, this package is **experimental** and subject to change!

Build validated PHP value objects out of JSON payloads, and serialize them back to JSON.
Backed by [JSON Schema](https://json-schema.org/) inside the types.

Schematic is **a bridge between PHP classes and JSON Schema**. A type owns its schema by implementing
`Neos\JsonSchema\ProvidesSchema` (returning it from a static `schema()`).

<!-- TOC -->
* [Schematic](#schematic)
  * [Installation](#installation)
  * [Quick start](#quick-start)
    * [Owning a schema is opt-in](#owning-a-schema-is-opt-in)
  * [The two entry points](#the-two-entry-points)
  * [Nothing is built without being validated first](#nothing-is-built-without-being-validated-first)
    * [Why the outer pass exists at all](#why-the-outer-pass-exists-at-all)
  * [Construction: the constructor, whatever its visibility](#construction-the-constructor-whatever-its-visibility)
    * [How the engine reaches a nested type](#how-the-engine-reaches-a-nested-type)
  * [Building is a chain, and the chain is yours](#building-is-a-chain-and-the-chain-is-yours)
  * [What a class looks like to the engine](#what-a-class-looks-like-to-the-engine)
  * [Deriving a schema from the class](#deriving-a-schema-from-the-class)
  * [Serializing back out](#serializing-back-out)
  * [Making sure the class and its schema still agree](#making-sure-the-class-and-its-schema-still-agree)
  * [Invariants the schema cannot express](#invariants-the-schema-cannot-express)
  * [Known limitations](#known-limitations)
  * [Architecture](#architecture)
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
    public static function fromString(string $value) {
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

$result = Schematic::instanciate(Post::class, ['slug' => 'hello-world', 'author' => 'Ada', 'text' => 'Hi']);

assert($result->success === true);
assert($result->value() instanceof Post);
assert($result->value()->slug->value === 'hello-world');

// and back out again — the same shape the schema describes
assert(json_encode($result->value()) === '{"slug":"hello-world","author":"Ada","text":"Hi"}');
```

The `static $schema` is used for in-memory caching. A schema is class-level and immutable, so building it once is a
good idea. This way, this package contains no caching or any other state at all.

## AutoDiscoveringSchema

## Instanciation of objects

TODO WRITE ME

(JSON Schema validation happens first)

### why we recommend private constructors


### Classes validating themselves

(explain "fromString" etc)

### Custom instanciation with Instanciator Chains



## Serialization of objects

TODO WRITE ME


## Known limitations

##### OLD

| Call                                       | What it does                                              |
|--------------------------------------------|-----------------------------------------------------------|
| `Schematic::instanciate($class, $input)`   | validate, then build — returns an `InstanciationResult<T>` |
| `Schematic::serialize($schema, $value)`    | read an instance back into primitives                     |

A schema is not one of them: it belongs to the class, and `Post::schema()` is how you ask for it. There is no facade
call wrapping that — the wrapper existed to check that the class owns one at all, and the parameter type says it now.

`instanciate()` returns a result rather than throwing, because a payload arriving from outside wants every issue in it
at once. A named constructor, which has no half-built value object to hand back, reads that same result with
`->valueOrThrow()` and gets a `CoercionFailed` instead.

`instanciate()` takes a `class-string<ProvidesSchema>` and its result is generic over it — `T` all the way through, so
`->value()` is the class that was asked for rather than a bare `object`, and a class that never opted in is refused
where it is *named*, at analysis time. Named as a *member* of one that did, reflection is all there is to go on, and
the refusal happens on the way through — a `LogicException`, because a schema this package derived cannot describe such
a member in the first place.

## Nothing is built without being validated first

The one entry point that builds validates against the class's own schema before it constructs anything —
`instanciate()` reports what is wrong, and `valueOrThrow()` turns that report into an exception:

```php
// ... Slug and Post from the quick start
use Neos\Schematic\CoercionFailed;
use Neos\Schematic\Schematic;

$slug = Schematic::instanciate(Slug::class, 'hello-world')->valueOrThrow();
assert($slug->value === 'hello-world');

$caught = null;
try {
    Schematic::instanciate(Slug::class, 'Hello World')->valueOrThrow();
} catch (CoercionFailed $exception) {
    $caught = $exception->issues->codes();
}
assert($caught === ['invalid_pattern']);
```

The `Slug` inside a `Post` goes through the very same check, in that very same pass: `Post::schema()` *embeds*
`Slug::schema()`, so judging the payload judges the slug. There is no second, unvalidated path through the engine —
and no second, redundant one either. What builds the instance afterwards only builds.

The one case that escapes it: a **hand-written** `schema()` describing a member more loosely than the member's own
schema does — an `additionalProperties: true` object where a `Slug` really goes. Nothing re-checks that member
against `Slug::schema()`, and `Slug`'s constructor is left as the last word on it. `AutoDiscoveringSchema` cannot
produce such a schema; writing one is saying you meant it.

A public constructor is still a public constructor, though: `new Slug('Hello World')` is plain PHP and nothing checks
it. If the invariant has to hold for *every* construction, make the constructor private and offer a named constructor
that goes through `Schematic::instanciate()` — see below.

### Why the outer pass exists at all

Because `Post::schema()` *embeds* `Slug::schema()`, a whole payload can be judged in one go — which is what lets a bad
request report everything wrong with it, each issue where it belongs:

```php
// ... Slug and Post from the quick start
use Neos\Schematic\Schematic;

$result = Schematic::instanciate(Post::class, ['slug' => 'Hello World', 'author' => 'Ada']);

assert($result->success === false);
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
        return Schematic::instanciate(self::class, $value)->valueOrThrow();
    }

    public static function schema(): Schema
    {
        return StringSchema::create(pattern: '^[a-z]+$');
    }
}

assert(Handle::fromString('ada')->value === 'ada');
assert(Schematic::instanciate(Handle::class, 'ada')->valueOrThrow()->value === 'ada');
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
and it calls `Schematic::instanciate()` itself — so calling it from the engine would turn the obvious `fromString()`
into unbounded recursion.

The consequence, and it is the intended trade: **a rule living in a named constructor body, rather than in the schema
or the constructor, is not enforced on the way in.** That is the same rule the package states for invariants generally
— the schema is the validator, the constructor is the last word.

## Building is a chain, and the chain is yours

Construction runs through a chain of links, each claiming one kind of thing and passing on everything else:

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

`Schematic::instanciate()` wires the built-in one, which is the whole of what the package knows how to build:

```php (no test)
new EnumCase(new ScalarValue(new ListOfItems(new NamedShape(new Unbuildable()))))
```

Order is the order they are asked, and the first to claim a class wins. `Unbuildable` is the end: what nothing
claimed is refused there rather than guessed at. `$root` is the whole chain, handed to every link, so descending into
a member starts at the top again — which is what makes a link of your own apply at every depth rather than only to
the value you named.

**When you would write one.** A schema with no single structural reading is the usual reason: a `oneOf` whose
branches are different kinds of thing cannot say what to build, so the built-in chain refuses it.

```php
use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\OneOfSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\ObjectProperties;
use Neos\Schematic\Instantiation\EnumCase;
use Neos\Schematic\Instantiation\Instantiator;
use Neos\Schematic\Instantiation\ListOfItems;
use Neos\Schematic\Instantiation\NamedShape;
use Neos\Schematic\Instantiation\ScalarValue;
use Neos\Schematic\Instantiation\Unbuildable;
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
    $unreached = Schematic::instanciate(Reference::class, 'dune');
} catch (\LogicException $exception) {
    $caught = $exception->getMessage();
}
assert(str_contains((string) $caught, 'could not be built by any Instanciator'));
```

A link says what it claims, builds it, and hands everything else to the next one:

```php
// ... Reference from above
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
    new EnumCase(new ScalarValue(new ListOfItems(new NamedShape(new Unbuildable())))),
);

// the engine builds and never judges, so validating is yours — once, against the schema the class owns
$input = ['id' => 'dune'];
assert(Reference::schema()->validate($input)->valid === true);

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
assert(Edition::schema()->validate($payload)->valid === true);

$edition = $instantiator->build(Edition::class, $payload, $instantiator);
assert($edition->of->id === 'dune');
assert($edition->year === 1965);
```

`NamedShape` built the `Edition`, and descending into its `of` member went back to the top of the chain, where
`BuildReference` claimed it — the built-in links never saw it.

**`Schematic::instanciate()` takes no chain**, deliberately: the facade is the package's own wiring, with nothing to
register with and nothing to configure. A consumer with links of its own owns the whole call — validate, then build —
which is two lines, and keeps *its* wiring somewhere it can see it.

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

One case the constructor cannot settle: a **one-member envelope whose member is a scalar**.
`Success{bool $success}` meaning `{"success": true}` is written exactly like `Slug{string $value}` meaning
`"a-slug"`. The table above reads both as Scalar — so for that one question the **schema decides**, in both
directions, since the class already publishes one and it says `type: object` where the value object says
`type: string`:

```php
use Neos\JsonSchema\BooleanSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;
use Neos\Schematic\Schematic;

final readonly class Success implements ProvidesSchema
{
    public function __construct(public bool $success = true) {}

    public static function schema(): Schema
    {
        return ObjectSchema::create(
            properties: ObjectProperties::create(success: BooleanSchema::create(const: true)),
            required: ['success'],
        );
    }
}

assert(Schematic::serialize(Success::schema(), new Success()) === ['success' => true]);
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
turn. `?T` / `T|null` gains a null branch, and a member typed as a **union** of several types becomes an `anyOf` over
them — a value that may be one of several shapes, described where it appears. `null` is not one of those shapes: it is
the member being absent, so `Foo|Bar|null` is a nullable `anyOf` of two rather than an `anyOf` of three.

Both directions follow that `anyOf`. Building picks the first branch the primitives satisfy; reading back picks the
branch the value *is* — its own class's schema, when that schema is one of the branches — so the constraints of the
branch still apply, `{}` versus `[]` included. A union has no properties of its own to read anything from.

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

Analysis rejects what it cannot describe with an `UnsupportedTypeException`: an enum with no cases, an intersection
type, a union with fewer than two substantive branches, `mixed`, and builtins that are not one of the four JSON
scalars. A recursive type raises a
`CircularReferenceException` naming the cycle, since an embedded schema cannot contain itself.

## Serializing back out

`Schematic::serialize()` takes **the schema as well as the value**, and that is what makes it the exact inverse of
`map()`. The class shape drives *extraction* — only the class knows an enum's backing value, a value object's single
member, a collection's `Traversable`:

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

$registry = Schematic::instanciate(Registry::class, ['entries' => [], 'names' => []])->value();

// the empty map is a map and the empty list is a list, because the schema says which is which
assert(json_encode(Schematic::serialize(Registry::schema(), $registry)) === '{"entries":{},"names":[]}');
```

There is deliberately no overload deriving the schema from `$value::class`. A consumer may be serializing through a
*declared* type rather than the runtime class — an API describing its 200 response by the return type its document
publishes — and reaching for the runtime class behind its back would quietly undo that. Where the runtime class *is*
the right answer, it is one line: `Schematic::serialize($value::schema(), $value)`.

Members the schema does not name are handed back untouched: a free-form map's values are described by nothing, so
there is nothing there to decide and guessing would be the mistake this avoids. That empty-map-or-list question is
the *whole* of what the schema is asked about a *value* out here — members keep the order extraction found them in,
since a JSON object is an unordered set of members. Which members exist is a different question, and a class and
its schema disagreeing about that is raised rather than emitted, since nothing downstream would notice.

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
`schema()` can drift — and every way it can is loud, at the moment it matters, rather than something a separate
audit has to go looking for:

| The disagreement | What happens |
|------------------|--------------|
| the schema does not require a parameter that has no default | `LogicException` while building: validation let the payload through without it, and the constructor has nothing to pass |
| the schema accepts `null` for a parameter that does not | `TypeError` from the constructor itself |
| the schema is not the kind of thing the class is | `UnextractableValue` from the serializer, naming the class |
| the schema has no single structural reading (a union of different kinds, an `allOf`, a `$ref`) | `UnextractableValue`, telling you to publish a schema that says which |
| the class emits a member the schema does not describe | `UnextractableValue` while serializing — nothing downstream would notice an undocumented field, so it is raised here |
| the schema publishes a property the constructor names nowhere | `UnextractableValue` while serializing — the document promises a member no value will ever carry |

The last two are the ones only this package can see. A request that is wrong is rejected by validation, with an
issue and a path; a *document* that is wrong is not something a client can report. Both are raised where the class
and its schema are both in hand, which is while reading an instance back out.

What no exception can see is a disagreement that needs a *value* to show itself: a member emitted with a type or a
`null` the schema refuses, or an enum case serializing to something outside the published `enum`. Those the
validator answers directly — the way out is JSON, so judge it as JSON:

```php
use Neos\JsonSchema\IntegerSchema;
use Neos\JsonSchema\ObjectSchema;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\JsonSchema\Support\ObjectProperties;
use Neos\Schematic\Schematic;

final readonly class Measurement implements ProvidesSchema
{
    public function __construct(public int|null $celsius) {}

    public static function schema(): Schema
    {
        static $schema = null;
        return $schema ??= ObjectSchema::create(
            properties: ObjectProperties::create(celsius: IntegerSchema::create()),   // never null, says the document
            required: ['celsius'],
        );
    }
}

$emitted = json_decode((string) json_encode(Schematic::serialize(Measurement::schema(), new Measurement(null))), true);

assert(Measurement::schema()->validate($emitted)->issues->codes() === ['invalid_type']);
```

That is one assertion in a test of the type, and it holds a class to its own document exactly as a request is held
to it. It goes through `json_encode` first because that is where the empty map becomes `{}` — the shape the
document describes, and the shape a client receives.

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
    Schematic::instanciate(DateRange::class, ['start' => 10, 'end' => 5]);
} catch (\InvalidArgumentException $exception) {
    $caught = $exception->getMessage();
}
assert($caught === 'start (10) must be less than end (5)');
```

To have such a rule reported as bad *input*, it has to reach the schema: express it there, or validate before mapping.
Nothing downstream turns an exception back into an issue.

## Known limitations

- **Constructor parameters must be named types, or a union of them.** Intersections are rejected. `mixed` and a bare
  `null` are not supported either — they report as nullable to reflection but describe no shape.
- **Recursive types are rejected**, with a `CircularReferenceException` naming the cycle. A schema embeds its children
  directly, so it cannot represent one. (`neos/jsonschema` ships a `ReferenceSchema`, so this may change.)
- **Tag-discriminated polymorphism is not built in.** A union member is resolved by *trying* its branches, which is
  what `anyOf` means; a payload whose type is decided by one of its own keys is not that. Reading the tag is a rule
  only the consumer knows, so it belongs in a link of its own — see [Building is a chain](#building-is-a-chain-and-the-chain-is-yours),
  where the built-in chain refuses exactly this and one link takes it over.
- **Serialization reads public properties.** A class with private state writes its own `jsonSerialize()`.

## Architecture

```
src/
  Schematic.php                          the two entry points
  InstanciationResult.php                the instance, or every reason the payload could not build one
  CoercionFailed.php                     input did not satisfy the class's schema
  UnextractableValue.php                 the instance does not expose what its shape names
  Reflection/ClassShape.php (+ Nature)   what a class is, and its constructor parameters
  Discovery/AutoDiscoveringSchema.php    a schema derived from the class, when a class asks for one
  Instantiation/Instantiator.php         one link of the chain that turns primitives into an instance
  Instantiation/{EnumCase, ...}.php      the links: an enum, one value, a list, a shape — then Unbuildable
  Serialization/Serializer.php           instance -> primitives, no schema involved
```

## Contribution

Contributions in the form of [issues](https://github.com/neos/schematic/issues) or
[pull requests](https://github.com/neos/schematic/pulls) are highly appreciated.
