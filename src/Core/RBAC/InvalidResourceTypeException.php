<?php

declare(strict_types=1);

namespace Whity\Core\RBAC;

use InvalidArgumentException;

/**
 * Raised when a resource-type slug does not conform to the naming pattern
 * required by {@see ResourceTypeRegistry} (WC-712 §2).
 *
 * A valid slug is lowercase, starts with a letter, and continues with letters,
 * digits or underscores — e.g. `ou`, `catalog_item`. Unlike a permission it
 * carries NO colon: a resource type names a kind of record, not a capability.
 */
class InvalidResourceTypeException extends InvalidArgumentException
{
    /**
     * Create an exception describing a slug that failed format validation.
     *
     * @param string $resourceType The offending resource-type slug.
     * @return self
     */
    public static function forResourceType(string $resourceType): self
    {
        return new self(
            "Invalid resource type '{$resourceType}': expected lowercase "
            . "'resource_type' format (letters, digits, underscores; no colon)"
        );
    }
}
