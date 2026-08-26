<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Attributes\Fixtures;

final readonly class ClassifiedContact
{
    private function __construct(
        public ContactName $name,
        public ContactStatus $status,
        public ContactPriority $priority,
        public ContactChannel $channel,
        public ContactStatus|null $previousStatus = null,
    ) {}
}
