<?php

declare(strict_types=1);

namespace Whity\Core\Inbox;

use InvalidArgumentException;

/**
 * Raised when an inbox-source registration does not conform to the rules
 * {@see InboxSourceRegistry} enforces (#881).
 *
 * Mirrors {@see \Whity\Core\Ou\InvalidOuTypeException} and
 * {@see \Whity\Core\Document\Routing\InvalidRoutingRuleException} deliberately:
 * the registries apply the same key grammar, so they refuse the same kinds of
 * malformed declaration in the same words, and somebody who has read one
 * message recognises the next.
 */
class InvalidInboxSourceException extends InvalidArgumentException
{
    /**
     * A key that failed format validation.
     */
    public static function forSlug(string $key): self
    {
        return new self(
            "Invalid inbox source key '{$key}': expected a bare lowercase slug "
            . '(letters, digits, underscores; no colon — the host applies the namespace)'
        );
    }

    /**
     * A key something already registered.
     *
     * Refused rather than overwritten. A silently replaced source would make the
     * caller's inbox depend on bootstrap order, and the symptom — items from the
     * wrong subsystem, or none — would appear nowhere near the registration that
     * caused it.
     */
    public static function forDuplicateKey(string $key): self
    {
        return new self(
            "Inbox source '{$key}' is already registered; each key may be claimed once"
        );
    }
}
