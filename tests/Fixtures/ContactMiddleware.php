<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use Neos\Schematic\Generation\SchemaGenerator;
use Neos\Schematic\Generation\SchemaMiddleware;
use Neos\Schematic\Schema\Schema;

/**
 * A minimal generation middleware for the fixture value objects — proves the generation seam with zero reflection.
 */
final class ContactMiddleware implements SchemaMiddleware
{
    public function process(string $className, SchemaGenerator $next, SchemaGenerator $root): Schema
    {
        return match ($className) {
            Contact::class => Fixtures::contact(),
            ContactName::class => Fixtures::contactName(),
            ContactAge::class => Fixtures::contactAge(),
            default => $next->generate($className),
        };
    }
}
