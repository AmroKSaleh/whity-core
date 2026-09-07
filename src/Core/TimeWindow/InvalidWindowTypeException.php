<?php

declare(strict_types=1);

namespace Whity\Core\TimeWindow;

use InvalidArgumentException;

/**
 * Raised when a time-window-type declaration does not conform to the rules
 * {@see WindowTypeRegistry} enforces (#1070).
 *
 * Mirrors {@see \Whity\Core\Ou\InvalidOuTypeException} deliberately: the two
 * registries apply the same namespacing rule, so they refuse the same kinds of
 * malformed declaration with the same wording, and a plugin author who has seen
 * one message recognises the other.
 */
class InvalidWindowTypeException extends InvalidArgumentException
{
    /**
     * A bare slug that failed format validation.
     *
     * @param string $slug The offending slug.
     */
    public static function forSlug(string $slug): self
    {
        return new self(
            "Invalid time-window type '{$slug}': expected a bare lowercase slug "
            . '(letters, digits, underscores; no colon — the host applies the namespace)'
        );
    }

    /**
     * A caller other than core claimed a reserved source, which would let it
     * mint UNPREFIXED keys and squat on the tenant's own namespace.
     *
     * @param string $source The reserved source that was claimed.
     */
    public static function forReservedSource(string $source): self
    {
        return new self(
            "Source '{$source}' is reserved for core time-window types; plugins are "
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
            "Source '{$source}' yields no usable namespace prefix; a time-window type "
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
            "Time-window type '{$key}' must be declared as an array of defaults "
            . "(e.g. ['label' => 'Growing season', 'parent' => 'crop_year'])"
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
        return new self("Time-window type '{$key}': {$field} {$reason}");
    }

    /**
     * A slug the source has already declared in this same registration.
     *
     * @param string $key The canonical key declared twice.
     */
    public static function forDuplicateKey(string $key): self
    {
        return new self(
            "Time-window type '{$key}' is already registered; a source may declare each slug once"
        );
    }

    /**
     * A declaration nesting inside a slug the same declaration does not contain.
     *
     * Deliberately not resolvable against the registry as a whole: a plugin may
     * only nest inside its OWN vocabulary, because it neither owns another
     * source's type nor can know whether a given tenant adopted it. Allowing a
     * cross-source parent would let one plugin's nesting decision reshape a
     * hierarchy the other plugin's author never saw.
     *
     * @param string $key    The canonical key of the declaring type.
     * @param string $parent The bare parent slug that was not declared alongside it.
     */
    public static function forUnknownParent(string $key, string $parent): self
    {
        return new self(
            "Time-window type '{$key}' nests inside '{$parent}', which this source does not "
            . 'declare; a type may only nest inside another type from the same declaration'
        );
    }

    /**
     * A declaration whose nesting closes a loop.
     *
     * @param string $key The canonical key at which the loop was detected.
     */
    public static function forNestingCycle(string $key): self
    {
        return new self(
            "Time-window type '{$key}' nests inside itself, directly or through its ancestors"
        );
    }
}
