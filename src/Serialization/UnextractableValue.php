<?php

declare(strict_types=1);

namespace Neos\Schematic\Serialization;

/**
 * Thrown when a {@see ValueExtractor} cannot read the state its Schema node describes — a value object whose
 * properties do not correspond to its schema, or one built by a custom InstanceFactory with no matching extractor.
 *
 * Unlike a coercion failure this is never caused by *input*: it means the Schema and the target class disagree,
 * which is a programming error. It therefore throws rather than returning a result object.
 */
final class UnextractableValue extends \RuntimeException {}
