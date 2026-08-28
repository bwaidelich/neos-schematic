<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A collection that is neither Traversable nor exposes its items under the variadic parameter's name: nothing can
 * read it back, which must fail loudly rather than emit an empty list.
 */
final readonly class OpaqueTags implements ProvidesSchema
{
    /**
     * @var list<Tag>
     */
    private array $hidden;

    public function __construct(Tag ...$tags)
    {
        $this->hidden = array_values($tags);
    }

    public function count(): int
    {
        return count($this->hidden);
    }

    public static function schema(): Schema
    {
        return AutoDiscoveringSchema::analyze(self::class);
    }
}
