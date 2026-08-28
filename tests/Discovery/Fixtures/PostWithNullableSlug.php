<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Discovery\Fixtures;

/**
 * A nullable *class* property: the null branch wraps the referenced type's own schema.
 */
final readonly class PostWithNullableSlug
{
    public function __construct(public Slug|null $slug) {}
}
