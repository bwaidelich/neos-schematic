<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use IteratorAggregate;
use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;
use Traversable;

/**
 * A collection answering "what is in me" itself: serialization iterates it rather than reading a property.
 *
 * @implements IteratorAggregate<int, Tag>
 */
final readonly class TagList implements IteratorAggregate, ProvidesSchema
{
    /**
     * @var list<Tag>
     */
    private array $items;

    public function __construct(Tag ...$tags)
    {
        $this->items = array_values($tags);
    }

    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->items);
    }

    public static function schema(): Schema
    {
        return AutoDiscoveringSchema::analyze(self::class);
    }
}
