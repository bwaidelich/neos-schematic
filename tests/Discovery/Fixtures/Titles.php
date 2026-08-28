<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Discovery\Fixtures;

/**
 * A collection: one variadic parameter, so the schema is an array of the item type.
 */
final readonly class Titles
{
    /**
     * @var list<Title>
     */
    public array $titles;

    public function __construct(Title ...$titles)
    {
        $this->titles = array_values($titles);
    }
}
