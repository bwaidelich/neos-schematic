<?php

declare(strict_types=1);

namespace Neos\Schematic\Instantiation;

use Neos\JsonSchema\Validation\Issue;
use Neos\JsonSchema\Validation\Issues;
use Neos\Schematic\Coercion\Coercer;
use Neos\Schematic\Coercion\CoercionResult;
use Neos\Schematic\Coercion\InvalidValue;
use Neos\Schematic\Schema\Schema;

/**
 * Convenience facade running both phases: {@see Coercer} (validate + normalize) then {@see Instantiator} (build),
 * returning a single {@see CoercionResult}. A construction-time {@see InvalidValue} is converted into an Issue.
 *
 * For pure validation (no objects) call the {@see Coercer} directly.
 */
final class Mapper
{
    private readonly Coercer $coercer;
    private readonly Instantiator $instantiator;

    public function __construct(Coercer|null $coercer = null, Instantiator|null $instantiator = null)
    {
        $this->coercer = $coercer ?? new Coercer();
        $this->instantiator = $instantiator ?? new Instantiator();
    }

    #[\NoDiscard('inspect the CoercionResult; discarding it drops the mapping outcome')]
    public function map(Schema $schema, mixed $input): CoercionResult
    {
        $coerced = $this->coercer->coerce($schema, $input);
        if (!$coerced->success) {
            return $coerced;
        }
        try {
            $instance = $this->instantiator->instantiate($schema, $coerced->value());
        } catch (InvalidValue $exception) {
            return CoercionResult::failure(Issues::create(Issue::create($exception->path, InvalidValue::ISSUE_CODE, $exception->getMessage())));
        }
        return CoercionResult::success($instance);
    }
}
