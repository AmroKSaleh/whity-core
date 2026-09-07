<?php

declare(strict_types=1);

namespace Whity\Core\Promotion;

use RuntimeException;

/**
 * A promotion that could not be created as described.
 *
 * Carries the FIELD as well as the reason, so an admin form can put the message
 * against the input that caused it instead of at the top of the page — which is
 * the difference between "must be between 1 and 100" being useful and being a
 * puzzle on a form with three numeric fields.
 */
final class PromotionValidationException extends RuntimeException
{
    public function __construct(
        public readonly string $field,
        public readonly string $reason,
    ) {
        parent::__construct("Invalid promotion '{$field}': {$reason}");
    }
}
