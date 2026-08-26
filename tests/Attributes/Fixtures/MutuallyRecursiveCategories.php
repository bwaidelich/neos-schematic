<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

use Neos\Schematic\Attributes\ListBased;

#[ListBased(itemClassName: MutuallyRecursiveCategory::class)]
final readonly class MutuallyRecursiveCategories
{
    /**
     * @var list<MutuallyRecursiveCategory>
     */
    public array $categories;

    private function __construct(MutuallyRecursiveCategory ...$categories)
    {
        $this->categories = array_values($categories);
    }
}
