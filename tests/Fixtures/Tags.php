<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\JsonSchema\ProvidesSchema;
use Neos\JsonSchema\Schema;
use Neos\Schematic\Discovery\AutoDiscoveringSchema;

/**
 * A collection: one variadic parameter, exposed as a public property of the same name.
 */
final readonly class Tags implements ProvidesSchema
{
    /**
     * @var list<Tag>
     */
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
