# Schematic

> [!WARNING]
> At this stage, this package is **experimental** and subject to change!

Turn unknown input — JSON payloads, form data, database rows — into validated PHP value objects, render those same
types back out as [JSON Schema](https://json-schema.org/), and read the objects themselves back out as JSON.

Schematic is a **reflection-free engine**: a `Schema` describes your classes as a JSON-Schema-inspired structure,
and the runtime path that coerces input and builds objects never reflects. Schemas are produced once by
*middleware* and can be cached as plain serialized data.

## Requirements

PHP 8.4 or higher.

## Installation

```bash
composer require neos/schematic
```

## Quick start

Describe your value objects with attributes, wire a `Schematic`, and map input into instances:

```php
use Neos\Schematic\Attributes\Description;
use Neos\Schematic\Attributes\IntegerBased;
use Neos\Schematic\Attributes\ReflectionMiddleware;
use Neos\Schematic\Attributes\StringBased;
use Neos\Schematic\Schematic;

#[Description('The full name of a contact')]
#[StringBased(minLength: 1, maxLength: 200)]
final readonly class ContactName
{
    private function __construct(public string $value) {}
}

#[IntegerBased(minimum: 0, maximum: 150)]
final readonly class ContactAge
{
    private function __construct(public int $value) {}
}

#[Description('A contact')]
final readonly class Contact
{
    private function __construct(
        public ContactName $name,
        public ContactAge $age,
    ) {}
}

$schematic = Schematic::create(new ReflectionMiddleware());

$result = $schematic->map(Contact::class, ['name' => 'John Doe', 'age' => 45]);

assert($result->success === true);

$contact = $result->value();
assert($contact instanceof Contact);
assert($contact->name->value === 'John Doe');
assert($contact->age->value === 45);
```

Note that the constructors are **private**: instances of `ContactName` can only come into existence through a
validated path, and `new ContactName('')` is not one of them. Schematic constructs them anyway.

## The two phases

Mapping is two distinct steps, and you can stop after the first:

1. **Coerce** — validate and normalize the raw input into schema-conformant *primitives*. Creates no objects,
   uses no reflection, and is usable on its own as a validator.
2. **Instantiate** — build the object graph from those primitives.

`coerce()` gives you phase 1 alone. Note that the numeric string `'45'` is normalized to the integer `45` — that
normalization is what makes this more than plain JSON Schema validation:

```php
// ...
$coerced = $schematic->coerce(Contact::class, ['name' => 'John Doe', 'age' => '45']);

assert($coerced->success === true);
assert($coerced->value() === ['name' => 'John Doe', 'age' => 45]);
```

Unknown keys are dropped from the coerced value rather than carried along — but only after validation has
accepted them (with `additionalProperties: false`, which the attribute middleware sets, an unknown key is an
error rather than something to drop).

## When input is invalid

Invalid input is expected, not exceptional, so nothing throws: `map()` and `coerce()` both return a
`CoercionResult` that is *either* a value *or* the aggregated issues. Every violation is reported, not just the
first:

```php
// ...
$result = $schematic->map(Contact::class, ['name' => '', 'age' => 500]);

assert($result->success === false);
assert(count($result->issues) === 2);

$issues = $result->issues->toArray();

assert($issues[0]->pathAsString() === 'name');
assert($issues[0]->code === 'too_short');
assert($issues[0]->message === 'Value must be at least 1 character(s) long');

assert($issues[1]->pathAsString() === 'age');
assert($issues[1]->code === 'too_big');
assert((string) $issues[1] === 'age: Value must be at most 150 (too_big)');
```

Each `Issue` carries a machine-readable `code` (the `Neos\JsonSchema\Validation\IssueCode` vocabulary), a human
message, and the `path` to the offending value — as a dotted string, or as an RFC 6901 JSON Pointer for error
formats like [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457):

```php
// ...
assert($issues[0]->path === ['name']);
assert($issues[0]->pathAsJsonPointer() === '/name');

$missing = $schematic->map(Contact::class, ['name' => 'John Doe']);
assert((string) $missing->issues->toArray()[0] === 'age: Missing required property "age" (required)');

$unknown = $schematic->map(Contact::class, ['name' => 'John Doe', 'age' => 45, 'nickname' => 'Johnny']);
assert((string) $unknown->issues->toArray()[0] === '<root>: Unrecognized property: nickname (unrecognized_keys)');
```

Reading `value()` on a failed result throws, so a result cannot be mistaken for a value by accident.

## Rendering JSON Schema

The same description that validates input renders back out as a JSON Schema document — so your API documentation
cannot disagree with your validation:

```php
// ...
$jsonSchema = $schematic->schemaFor(Contact::class)->toJsonSchema();

$expected = <<<'JSON'
{
    "type": "object",
    "description": "A contact",
    "properties": {
        "name": {
            "type": "string",
            "description": "The full name of a contact",
            "minLength": 1,
            "maxLength": 200
        },
        "age": {
            "type": "integer",
            "minimum": 0,
            "maximum": 150
        }
    },
    "additionalProperties": false,
    "required": [
        "name",
        "age"
    ]
}
JSON;

assert(json_encode($jsonSchema, JSON_PRETTY_PRINT) === $expected);
```

The result is a `Neos\JsonSchema\Schema` from the [neos/jsonschema](https://github.com/neos/jsonschema) package,
which is `JsonSerializable` and can also validate values on its own.

## Serializing back out

The reverse of `map()`: `serialize()` reads an instance back into primitives, ready for `json_encode()`.

```php
// ...
$contact = $schematic->map(Contact::class, ['name' => 'John Doe', 'age' => 45])->value();

assert($schematic->serialize(Contact::class, $contact) === ['name' => 'John Doe', 'age' => 45]);
assert(json_encode($schematic->serialize(Contact::class, $contact)) === '{"name":"John Doe","age":45}');
```

This is driven by the **same** `Schema` that validates input and renders to JSON Schema, so what you emit cannot
drift away from what you advertise — the output always satisfies the schema you published:

```php
// ...
$primitives = $schematic->serialize(Contact::class, $contact);
assert($schematic->schemaFor(Contact::class)->toJsonSchema()->validate($primitives)->valid === true);
```

That guarantee is why serialization is schema-driven rather than a generic reflecting normalizer: `json_encode()`
on a value object emits whatever the class happens to expose, with nothing tying it to the schema. Mechanically,
each `Schema` node names a `ValueExtractor` by class-string exactly as it names an `InstanceFactory` — the
`ReflectionMiddleware` stamps both, so anything it can map, it can read back.

Every property the schema declares is emitted, including those equal to their constructor default: the schema
advertises them, so omitting them would make the document narrower than the contract.

A value object the extractor cannot read — a custom `InstanceFactory` with no matching extractor, or a collection
that neither implements `IteratorAggregate` nor exposes its items under the constructor parameter's name — throws
`Serialization\UnextractableValue`. That is a mismatch between a class and its schema, i.e. a programming error,
so unlike invalid *input* it is not returned as a result.

## Invariants the schema cannot express

Some rules span several properties — `start < end` — and no JSON Schema keyword covers them. Enforce those in the
constructor as you normally would: an `\InvalidArgumentException` thrown while constructing is caught and turned
into an issue, so it joins the same result instead of blowing up the call:

```php
use Neos\Schematic\Attributes\ReflectionMiddleware;
use Neos\Schematic\Coercion\InvalidValue;
use Neos\Schematic\Schematic;

final readonly class DateRange
{
    private function __construct(
        public int $start,
        public int $end,
    ) {
        if ($start >= $end) {
            throw new \InvalidArgumentException(sprintf('start (%d) must be less than end (%d)', $start, $end));
        }
    }
}

$schematic = Schematic::create(new ReflectionMiddleware());

$result = $schematic->map(DateRange::class, ['start' => 10, 'end' => 5]);

assert($result->success === false);
assert($result->issues->toArray()[0]->code === InvalidValue::ISSUE_CODE);
assert($result->issues->toArray()[0]->message === 'start (10) must be less than end (5)');
```

`DateRange` carries no attributes at all — a class without them is described by its constructor, which is often
all you need. To signal an invariant explicitly (rather than relying on the `\InvalidArgumentException`
adaptation), throw `Neos\Schematic\Coercion\InvalidValue`; any *other* exception propagates, because that is a
bug rather than bad input.

## Attributes

The attribute classes and the middleware that reads them live in `Neos\Schematic\Attributes`.

| Attribute | Applies to | Effect |
| --- | --- | --- |
| `#[StringBased(minLength, maxLength, pattern, format)]` | class | a string wrapped in a class |
| `#[IntegerBased(minimum, maximum)]` | class | an integer wrapped in a class |
| `#[FloatBased(minimum, maximum)]` | class | a float wrapped in a class |
| `#[ListBased(itemClassName, minCount, maxCount)]` | class | a typed collection |
| `#[Description(value)]` | class | the JSON Schema `description` |
| `#[Ignore]` | constructor parameter | excluded from the schema (the parameter must have a default) |

A class with none of these is described by its constructor parameters — a *shape*, whose required properties are
the parameters without a default.

`format` takes a `Neos\JsonSchema\Support\StringFormat` and is enforced, not just annotated:

```php
use Neos\JsonSchema\Support\StringFormat;
use Neos\JsonSchema\Validation\IssueCode;
use Neos\Schematic\Attributes\ListBased;
use Neos\Schematic\Attributes\ReflectionMiddleware;
use Neos\Schematic\Attributes\StringBased;
use Neos\Schematic\Schematic;

#[StringBased(format: StringFormat::email)]
final readonly class EmailAddress
{
    private function __construct(public string $value) {}
}

#[ListBased(itemClassName: EmailAddress::class, minCount: 1)]
final readonly class EmailAddresses
{
    /** @var list<EmailAddress> */
    public array $addresses;

    private function __construct(EmailAddress ...$addresses)
    {
        $this->addresses = array_values($addresses);
    }
}

$schematic = Schematic::create(new ReflectionMiddleware());

$result = $schematic->map(EmailAddresses::class, ['john@example.com', 'jane@example.com']);
assert($result->success === true);
assert(count($result->value()->addresses) === 2);
assert($result->value()->addresses[0] instanceof EmailAddress);

$invalid = $schematic->map(EmailAddresses::class, ['john@example.com', 'not-an-email']);
assert($invalid->success === false);
assert($invalid->issues->toArray()[0]->code === IssueCode::InvalidFormat->value);
assert($invalid->issues->toArray()[0]->pathAsString() === '1');

$empty = $schematic->map(EmailAddresses::class, []);
assert($empty->issues->toArray()[0]->code === IssueCode::TooFewItems->value);
```

A `#[ListBased]` class takes its items either variadically (as above) or as a single array argument.

### Enums

An enum is described by its cases, with no attribute needed. A **backed** enum uses its backing values (and an
`int` backing type gives an integer schema); a **pure** enum uses its case names.

```php
use Neos\JsonSchema\Validation\IssueCode;
use Neos\Schematic\Attributes\Description;
use Neos\Schematic\Attributes\ReflectionMiddleware;
use Neos\Schematic\Schematic;

#[Description('Whether an account is still reachable')]
enum Status: string
{
    case active = 'active';
    case archived = 'archived';
}

enum Channel
{
    case email;
    case phone;
}

$schematic = Schematic::create(new ReflectionMiddleware());

assert($schematic->map(Status::class, 'archived')->value() === Status::archived);
// a pure enum is addressed by case name
assert($schematic->map(Channel::class, 'phone')->value() === Channel::phone);

$invalid = $schematic->map(Status::class, 'deleted');
assert($invalid->success === false);
assert($invalid->issues->toArray()[0]->code === IssueCode::InvalidEnumValue->value);
```

The rendered JSON Schema is a plain `enum` constraint:

```php
// ...
$json = json_encode($schematic->schemaFor(Status::class)->toJsonSchema());
assert($json === '{"type":"string","description":"Whether an account is still reachable","enum":["active","archived"]}');
```

An enum declaring no cases is rejected with an `UnsupportedTypeException` — it describes a type no value can
inhabit.

### Nullable properties

A constructor parameter typed `Foo|null` accepts `null` in addition to `Foo`. Nullability and *optionality* are
independent: a parameter without a default stays **required**, so the key must be present — `null` is simply an
accepted value for it. Give it a default and it becomes optional as usual.

```php
use Neos\JsonSchema\Validation\IssueCode;
use Neos\Schematic\Attributes\ReflectionMiddleware;
use Neos\Schematic\Attributes\StringBased;
use Neos\Schematic\Schematic;

#[StringBased(minLength: 1)]
final readonly class Nickname
{
    private function __construct(public string $value) {}
}

final readonly class Player
{
    private function __construct(
        public string $name,
        public Nickname|null $nickname,
    ) {}
}

$schematic = Schematic::create(new ReflectionMiddleware());

$withNickname = $schematic->map(Player::class, ['name' => 'Ada', 'nickname' => 'The Countess']);
assert($withNickname->value()->nickname instanceof Nickname);

$withoutNickname = $schematic->map(Player::class, ['name' => 'Ada', 'nickname' => null]);
assert($withoutNickname->value()->nickname === null);
```

The value object is never constructed with `null` — instantiation short-circuits first, so an
`InstanceFactory` only ever sees a real value. Constraints on the non-`null` side still apply, and you get the
*precise* issue rather than a vague "matches nothing":

```php
// ...
$tooShort = $schematic->map(Player::class, ['name' => 'Ada', 'nickname' => '']);
assert($tooShort->success === false);
assert($tooShort->issues->toArray()[0]->code === IssueCode::TooShort->value);
assert($tooShort->issues->toArray()[0]->pathAsString() === 'nickname');
```

In the rendered JSON Schema a nullable node becomes `anyOf: [<the type>, {"type": "null"}]`:

```php
// ...
$json = json_encode($schematic->schemaFor(Player::class)->toJsonSchema());
assert(str_contains((string) $json, '"anyOf":[{"type":"string","minLength":1},{"type":"null"}]'));
// still required — nullable is not the same as optional
assert(str_contains((string) $json, '"required":["name","nickname"]'));
```

## Without the attributes

The attributes are **opt-in batteries, not the engine**. They live in a namespace of their own that depends on
the engine and that the engine never references — you can ignore them entirely and describe your types yourself,
which is also how you support types you don't own (a third-party class, an enum, a `DateTimeImmutable`).

A `SchemaMiddleware` recognises a class and returns a `Schema` for it; anything it doesn't recognise it passes to
`$next`. An `InstanceFactory` says how to build the object once the value has been validated:

```php
use Neos\JsonSchema\StringSchema;
use Neos\JsonSchema\Support\StringFormat;
use Neos\Schematic\Attributes\ReflectionMiddleware;
use Neos\Schematic\Generation\SchemaGenerator;
use Neos\Schematic\Generation\SchemaMiddleware;
use Neos\Schematic\Instantiation\InstanceFactory;
use Neos\Schematic\Instantiation\Instantiator;
use Neos\Schematic\Schema\Schema;
use Neos\Schematic\Schematic;

final class DateTimeFactory implements InstanceFactory
{
    public function instantiate(Schema $schema, mixed $value, Instantiator $instantiator): mixed
    {
        return new \DateTimeImmutable((string) $value);
    }
}

final class DateTimeMiddleware implements SchemaMiddleware
{
    public function process(string $className, SchemaGenerator $next, SchemaGenerator $root): Schema
    {
        if ($className !== \DateTimeImmutable::class) {
            return $next->generate($className);
        }
        return Schema::scalar(
            StringSchema::create(format: StringFormat::date_time),
            \DateTimeImmutable::class,
            DateTimeFactory::class,
        );
    }
}

final readonly class Appointment
{
    private function __construct(
        public string $title,
        public \DateTimeImmutable $start,
    ) {}
}

$schematic = Schematic::create(new DateTimeMiddleware(), new ReflectionMiddleware());

$result = $schematic->map(Appointment::class, ['title' => 'Standup', 'start' => '2024-01-15T09:00:00+00:00']);

assert($result->success === true);
assert($result->value()->start instanceof \DateTimeImmutable);
assert($result->value()->start->format('Y-m-d H:i') === '2024-01-15 09:00');

$invalid = $schematic->map(Appointment::class, ['title' => 'Standup', 'start' => 'yesterday']);
assert((string) $invalid->issues->toArray()[0] === 'start: Value is not a valid "date-time" (invalid_format)');
```

Middleware is ordered: the first one that recognises a class wins, and each may also call `$next` and *augment*
the result. Nested types re-enter the pipeline from the top, which is why `ReflectionMiddleware` picks up the
`DateTimeImmutable` property above without knowing anything about it. A factory is referenced **by class-string**,
never as an instance, so the whole `Schema` graph stays serializable.

Middleware is also how the engine stays honest about reflection: `ReflectionMiddleware` reflects while *building*
a schema, and the result is plain data that a cache can serve instead.

## Self-describing value objects

A class can expose its own type by implementing `Neos\JsonSchema\ProvidesSchema` — a contract that depends on
`neos/jsonschema` alone, so a package can describe its value objects without taking on schematic as a dependency.
`ProvidesSchemaMiddleware` bridges any such class into a coercible one, calling the conventional named
constructor (`fromString`, `fromInteger`, `fromFloat`, `fromBoolean`, `fromArray`, or `from`):

```php
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema as JsonSchema;
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Generation\Middleware\ProvidesSchemaMiddleware;
use Neos\Schematic\Schematic;

final readonly class ProductId implements ProvidesSchema
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public static function schema(): JsonSchema
    {
        return StringSchema::create(description: 'A product identifier', pattern: '^P-\d{4}$');
    }
}

$schematic = Schematic::create(new ProvidesSchemaMiddleware());

$result = $schematic->map(ProductId::class, 'P-1234');
assert($result->success === true);
assert($result->value() instanceof ProductId);
assert($result->value()->value === 'P-1234');

$invalid = $schematic->map(ProductId::class, 'nope');
assert($invalid->success === false);
assert($invalid->issues->toArray()[0]->code === 'invalid_pattern');
```

This bridge covers value objects backed by a *scalar* schema. Describe composite ones with a middleware of your
own (or with the attributes).

## Caching

Generating a schema reflects; using one does not. Put `CachingMiddleware` first in the chain and the reflection
happens once per class, after which a PSR-16 cache serves the serialized graph:

<details>
<summary>The throwaway <code>InMemoryCache</code> used below</summary>

Any PSR-16 implementation will do — `symfony/cache` and `laminas/laminas-cache` both ship one. This is the
minimal version, spelled out so the example below runs as written:

```php
final class InMemoryCache implements \Psr\SimpleCache\CacheInterface
{
    /** @var array<string, mixed> */
    private array $items = [];

    public int $hits = 0;
    public int $misses = 0;

    public function get(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, $this->items)) {
            $this->misses++;
            return $default;
        }
        $this->hits++;
        return $this->items[$key];
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->items[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->items = [];
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->get($key, $default);
        }
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }
}
```

</details>

```php
// ...
use Neos\Schematic\Attributes\ReflectionMiddleware;
use Neos\Schematic\Attributes\StringBased;
use Neos\Schematic\Generation\Middleware\CachingMiddleware;
use Neos\Schematic\Schematic;

#[StringBased(minLength: 1)]
final readonly class Sku
{
    private function __construct(public string $value) {}
}

$cache = new InMemoryCache(); // any PSR-16 cache

$schematic = Schematic::create(new CachingMiddleware($cache), new ReflectionMiddleware());

assert($schematic->map(Sku::class, 'ABC-1')->value()->value === 'ABC-1');
assert($cache->misses === 1);

// the second schema for the same class comes from the cache, without reflecting
assert($schematic->map(Sku::class, 'ABC-2')->value()->value === 'ABC-2');
assert($cache->hits === 1);
```

Because nested types re-enter the pipeline, each named type is cached independently.

## Factories with dependencies

Factories and Extractors are resolved by a `FactoryResolver` / `ExtractorResolver`. The default one builds every
entry with `new`, so a factory must have no required constructor arguments. If a factory needs collaborators, hand
the application's PSR-11 container to `ContainerFactoryResolver` — anything the container doesn't know is still
built with `new`:

```php
use Neos\JsonSchema\StringSchema;
use Neos\Schematic\Generation\SchemaFactory;
use Neos\Schematic\Generation\SchemaGenerator;
use Neos\Schematic\Generation\SchemaMiddleware;
use Neos\Schematic\Instantiation\ContainerFactoryResolver;
use Neos\Schematic\Instantiation\InstanceFactory;
use Neos\Schematic\Instantiation\Instantiator;
use Neos\Schematic\Schema\Schema;
use Neos\Schematic\Schematic;
use Psr\Container\ContainerInterface;

final readonly class Slug
{
    public function __construct(public string $value) {}
}

final readonly class Slugifier
{
    public function slugify(string $value): string
    {
        return strtolower(str_replace(' ', '-', trim($value)));
    }
}

final readonly class SlugFactory implements InstanceFactory
{
    public function __construct(private Slugifier $slugifier) {}

    public function instantiate(Schema $schema, mixed $value, Instantiator $instantiator): mixed
    {
        return new Slug($this->slugifier->slugify((string) $value));
    }
}

final class SlugMiddleware implements SchemaMiddleware
{
    public function process(string $className, SchemaGenerator $next, SchemaGenerator $root): Schema
    {
        if ($className !== Slug::class) {
            return $next->generate($className);
        }
        return Schema::scalar(StringSchema::create(minLength: 1), Slug::class, SlugFactory::class);
    }
}

$container = new class (new Slugifier()) implements ContainerInterface {
    public function __construct(private readonly Slugifier $slugifier) {}

    public function has(string $id): bool
    {
        return $id === SlugFactory::class;
    }

    public function get(string $id): mixed
    {
        return new SlugFactory($this->slugifier);
    }
};

$schematic = new Schematic(
    new SchemaFactory(new SlugMiddleware()),
    instantiator: new Instantiator(new ContainerFactoryResolver($container)),
);

$result = $schematic->map(Slug::class, 'Hello World');

assert($result->success === true);
assert($result->value()->value === 'hello-world');
```

## Entry points

`Schematic` is a thin facade; every phase remains individually usable, and injecting the piece you need keeps
your own seams honest:

| Class | Role |
| --- | --- |
| `Schematic` | the facade: `schemaFor()`, `map()`, `coerce()`, `serialize()` |
| `Generation\SchemaFactory` | the wired middleware chain: `for(class): Schema` |
| `Coercion\Coercer` | phase 1 on a `Schema` you already have |
| `Instantiation\Instantiator` | phase 2 |
| `Instantiation\Mapper` | both phases on a `Schema` you already have |
| `Serialization\Serializer` | the way back out, on a `Schema` you already have |

## Known limitations

- An **interface or abstract class** describes no shape of its own, so the middleware passes it on and an
  unhandled one ends in an `UnsupportedTypeException`. Polymorphism is not supported: a payload whose type is
  decided by one of its own keys has no schema this package can generate.
- Constructor parameters must be **single named types**. Genuine union and intersection types are rejected —
  but a **nullable** type (`Foo|null`, equivalently `?Foo`) is supported, see below. `mixed` and a bare `null`
  are not: they report as nullable to reflection but describe no shape, so they are still rejected.
- **Recursive types are rejected**, with a `Generation\CircularReferenceException` naming the cycle path. A
  `Schema` node holds its children directly, so it cannot represent a cycle — and the caching middleware cannot
  break one either, since it stores an entry only *after* generation finishes. This covers the innocuous-looking
  `Node|null $parent` as well as cycles through several classes.
- `array` and `iterable` parameters are rejected — describe a collection as a `#[ListBased]` class instead.
- `#[Description]` is read on classes only; on a constructor parameter it is currently ignored.
- The **combinators** (`anyOf`, `oneOf`, `allOf`, `not`) can be *validated* by `neos/jsonschema`, but coercion
  cannot resolve them: the `Coercer` throws rather than guess.

## Architecture

Every PHP example in this README is executed by `tests/Documentation/ReadmeCodeBlockTest.php`, so the
documentation cannot drift from the code.

## Contribution

Contributions in the form of [issues](https://github.com/neos/schematic/issues) or
[pull requests](https://github.com/neos/schematic/pulls) are highly appreciated.
