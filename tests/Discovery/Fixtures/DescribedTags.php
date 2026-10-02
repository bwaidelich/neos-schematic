<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Discovery\Fixtures;

use Neos\Schematic\Discovery\Description;

/**
 * A collection described as a whole, and its items one by one: the variadic parameter *is* the item.
 */
#[Description('Keywords of a post')]
final readonly class DescribedTags
{
    /**
     * @var list<string>
     */
    public array $tags;

    public function __construct(#[Description('One keyword')] string ...$tags)
    {
        $this->tags = array_values($tags);
    }
}
