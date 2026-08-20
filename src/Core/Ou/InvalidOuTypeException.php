<?php

declare(strict_types=1);

namespace Whity\Core\Ou;

use InvalidArgumentException;

/**
 * Raised when an OU-type declaration does not conform to the rules
 * {@see OuTypeRegistry} enforces (#822).
 *
 * Mirrors {@see \Whity\Core\RBAC\InvalidResourceTypeException} deliberately: the
 * two registries apply the same namespacing rule, so they refuse the same kinds
 * of malformed declaration with the same wording, and a plugin author who has
 * seen one message recognises the other.
 */
class InvalidOuTypeException extends InvalidArgumentException
{
    /**
     * A bare slug that failed format validation.
     *
     * @param string $slug The offending slug.
     */
    public static function forSlug(string $slug): self
    {
        return new self(
            "Invalid OU type '{$slug}': expected a bare lowercase slug "
            . '(letters, digits, underscores; no colon — the host applies the namespace)'
        );
    }

    /**
     * A caller other than core claimed the reserved `core` source, which would
     * let it mint UNPREFIXED keys and squat on the tenant's own namespace.
     *
     * @param string $source The reserved source that was claimed.
     */
    public static function forReservedSource(string $source): self
    {
        return new self(
            "Source '{$source}' is reserved for core OU types; plugins are "
            . 'namespaced under their own plugin name'
        );
    }

    /**
     * A source name from which no usable namespace prefix could be derived, so
     * its types would be stored unprefixed and could collide with a tenant's.
     *
     * @param string $source The unusable source name.
     */
    public static function forSource(string $source): self
    {
        return new self(
            "Source '{$source}' yields no usable namespace prefix; an OU type "
            . 'source must start with a letter'
        );
    }

    /**
     * A declaration whose body is not an array of defaults.
     *
     * @param string $key The canonical key the declaration would have taken.
     */
    public static function forMalformedDeclaration(string $key): self
    {
        return new self(
            "OU type '{$key}' must be declared as an array of defaults "
            . "(e.g. ['label' => 'Clinic', 'sort_order' => 30])"
        );
    }

    /**
     * A declaration field with the wrong type or an unusable value.
     *
     * @param string $key    The canonical key.
     * @param string $field  The offending field.
     * @param string $reason What was expected.
     */
    public static function forField(string $key, string $field, string $reason): self
    {
        return new self("OU type '{$key}': {$field} {$reason}");
    }

    /**
     * A slug the source has already declared in this same registration.
     *
     * @param string $key The canonical key declared twice.
     */
    public static function forDuplicateKey(string $key): self
    {
        return new self(
            "OU type '{$key}' is already registered; a source may declare each slug once"
        );
    }
}
