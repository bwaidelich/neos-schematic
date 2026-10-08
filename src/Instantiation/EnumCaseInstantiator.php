<?php

declare(strict_types=1);

namespace Neos\Schematic\Instantiation;

/**
 * Enum cases are singletons, so they are looked up rather than built: a *backed* enum by its backing value, a
 * *pure* one by its case name — mirroring what the schema published for it.
 *
 * @api
 */
final readonly class EnumCaseInstantiator implements Instantiator
{
    public function __construct(private Instantiator $next) {}

    /**
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    public function build(string $className, mixed $value, Instantiator $root): object
    {
        if (!is_subclass_of($className, \UnitEnum::class)) {
            return $this->next->build($className, $value, $root);
        }
        $case = self::case($className, $value);
        // a case of the enum that was asked for, which is what makes it the T the caller named
        return $case instanceof $className
            ? $case
            : throw new \LogicException(sprintf('"%s" resolved to a case of %s', $className, $case::class), 1783600410);
    }

    /**
     * @param class-string<\UnitEnum> $className the guard above established it, which is why nothing re-asks
     */
    private static function case(string $className, mixed $value): \UnitEnum
    {
        if (is_subclass_of($className, \BackedEnum::class)) {
            if (!is_string($value) && !is_int($value)) {
                throw new \LogicException(sprintf('Expected a string or integer to resolve a case of "%s", got %s', $className, get_debug_type($value)), 1783600407);
            }
            return $className::tryFrom($value)
                ?? throw new \LogicException(sprintf('Value "%s" is not a case of enum "%s"', $value, $className), 1783600408);
        }
        foreach ($className::cases() as $case) {
            if ($case->name === $value) {
                return $case;
            }
        }
        throw new \LogicException(sprintf('Value "%s" is not a case of enum "%s"', is_scalar($value) ? (string) $value : get_debug_type($value), $className), 1783600409);
    }
}
