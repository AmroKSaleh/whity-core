<?php

declare(strict_types=1);

namespace Whity\Sdk\Expression;

use RuntimeException;

/**
 * An expression that passed validation but can't be evaluated on these values:
 * a type mismatch (text plus a number), or the evaluation budget running out.
 *
 * `kind` is one of {@see self::TYPE} or {@see self::BUDGET}, so a caller (the
 * rules runtime, a preview) can say which without parsing the message, and the
 * TypeScript evaluator throws the same kinds for the same inputs.
 */
final class ExpressionError extends RuntimeException
{
    public const TYPE = 'type';
    public const BUDGET = 'budget';

    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }
}
