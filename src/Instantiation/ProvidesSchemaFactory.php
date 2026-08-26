<?php

declare(strict_types=1);

namespace Neos\Schematic\Instantiation;

use Neos\Schematic\Schema\Schema;

/**
 * The {@see InstanceFactory} the {@see \Neos\Schematic\Generation\Middleware\ProvidesSchemaMiddleware} stamps onto the schema
 * of a class that describes itself via {@see \Neos\JsonSchema\ProvidesSchema}.
 *
 * The value has already been validated + normalized against the class's own schema in phase 1, so this simply hands
 * it to a conventional named constructor on the target, chosen by the primitive's type (`fromString`, `fromInteger`,
 * `fromFloat`, `fromBoolean`, `fromArray`), falling back to a universal `from()`.
 */
final class ProvidesSchemaFactory implements InstanceFactory
{
    public function instantiate(Schema $schema, mixed $value, Instantiator $instantiator): mixed
    {
        /** @var class-string $target */
        $target = $schema->target ?? throw new \LogicException(sprintf('%s requires the schema node to name a target class', self::class), 1751885100);
        $preferred = match (get_debug_type($value)) {
            'string' => 'fromString',
            'int' => 'fromInteger',
            'float' => 'fromFloat',
            'bool' => 'fromBoolean',
            'array' => 'fromArray',
            default => 'from',
        };
        $method = method_exists($target, $preferred) ? $preferred : 'from';
        if (!method_exists($target, $method)) {
            throw new \LogicException(sprintf('Target "%s" has neither a "%s" nor a "from" named constructor', $target, $preferred), 1751885101);
        }
        return $target::$method($value);
    }
}
