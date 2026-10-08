<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

/**
 * A plain record whose one member is a union of a plain record and a value object owning its schema — the branch
 * a value is built as is decided by the derived schema of each.
 */
final readonly class ArticleOrSlug
{
    public function __construct(public Article|Slug $held) {}
}
