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

    /**
     * A caller other than core tried to register under the reserved `core`
     * source, which would let it mint UNPREFIXED keys and shadow a core type.
     *
     * @param string $source The reserved source that was claimed.
     */
    public static function forReservedSource(string $source): self
    {
        return new self(
            "Source '{$source}' is reserved for core resource types; "
            . 'plugins are namespaced under their own plugin name'
        );
    }

    /**
     * A source name from which no usable namespace prefix could be derived, so
     * its types would be stored unprefixed and could collide with core.
     *
     * @param string $source The unusable source name.
     */
    public static function forSource(string $source): self
    {
        return new self(
            "Source '{$source}' yields no usable namespace prefix; a resource "
            . 'type source must start with a letter'
        );
    }
}
