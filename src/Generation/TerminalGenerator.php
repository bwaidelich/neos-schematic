<?php

declare(strict_types=1);

namespace Neos\Schematic\Generation;

use Neos\Schematic\Schema\Schema;

/**
 * The innermost handler of the pipeline: if no middleware handled the class, generation fails loud.
 *
 * TODO: Rename "FailingGenerator" or similar
 *
 * @internal
 */
final class TerminalGenerator implements SchemaGenerator
{
    public function generate(string $className): Schema
    {
        throw UnsupportedTypeException::forClass($className);
    }
}
