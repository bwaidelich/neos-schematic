<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

use Neos\Schematic\Attributes\ListBased;

#[ListBased(itemClassName: ContactName::class, minCount: 1)]
final readonly class ContactNames
{
    /**
     * @var list<ContactName>
     */
    public array $names;

    private function __construct(ContactName ...$names)
    {
        $this->names = array_values($names);
    }
}
