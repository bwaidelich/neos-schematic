<?php

declare(strict_types=1);

namespace Neos\Schematic\Instantiation;

/**
 * The end of the chain: what no link claimed.
 *
 * Reached when the schema published for a class has no single structural reading, so nothing could say what to
 * build from the primitives — and a client that means to support such a schema says so by putting a link of its
 * own in front of this one.
 *
 * @api
 */
final readonly class UnbuildableInstantiator implements Instantiator
{
    /**
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    public function build(string $className, mixed $value, Instantiator $root): object
    {
        throw new \LogicException(sprintf(
            'The schema published for "%s" could not be built by any Instantiator, from: %s',
            $className,
            self::render($value),
        ), 1783600412);
    }

    private static function render(mixed $value): string
    {
        $rendered = json_encode($value);
        if ($rendered === false) {
            return get_debug_type($value);
        }
        return strlen($rendered) <= 200 ? $rendered : substr($rendered, 0, 200) . '…';
    }
}
