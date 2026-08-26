<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

use Neos\Schematic\Attributes\ListBased;

/**
 * A list-based value object that hides its items: not Traversable, and the items do not live under the
 * constructor parameter's name. Nothing can read it back out, which must fail loudly rather than silently.
 */
#[ListBased(itemClassName: ContactName::class)]
final readonly class OpaqueList
{
    /**
     * @var list<ContactName>
     */
    private array $hidden;

    private function __construct(ContactName ...$names)
    {
        $this->hidden = array_values($names);
    }

    public function count(): int
    {
        return count($this->hidden);
    }
}
