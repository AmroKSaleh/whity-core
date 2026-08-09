<?php

declare(strict_types=1);

namespace Whity\Core\Health;

use InvalidArgumentException;

/**
 * Raised when a declared health probe does not conform to what
 * {@see HealthProbeRegistry} accepts.
 *
 * A valid probe key is lowercase, starts with a letter, and continues with
 * letters, digits or underscores — e.g. `database`, `catalog_sync`. It carries
 * NO colon: the colon is the namespace separator the host applies, so allowing
 * one in a declared key would let a plugin write its own prefix.
 */
class InvalidHealthProbeException extends InvalidArgumentException
{
    /** A key that failed format validation. */
    public static function forProbeKey(string $key): self
    {
        return new self(
            "Invalid health probe key '{$key}': expected lowercase "
            . "'probe_key' format (letters, digits, underscores; no colon)"
        );
    }

    /**
     * A caller other than core tried to register under the reserved `core`
     * source, which would let it mint UNPREFIXED keys and shadow a core probe.
     */
    public static function forReservedSource(string $source): self
    {
        return new self(
            "Source '{$source}' is reserved for core health probes; "
            . 'plugins are namespaced under their own plugin name'
        );
    }

    /**
     * A source name from which no usable namespace prefix could be derived, so
     * its probes would be stored unprefixed and could collide with core.
     */
    public static function forSource(string $source): self
    {
        return new self(
            "Source '{$source}' yields no usable namespace prefix; a health "
            . 'probe source must start with a letter'
        );
    }

    /** Something other than a HealthProbeDefinition appeared in the declaration. */
    public static function forDefinition(mixed $definition): self
    {
        return new self(
            'A health probe declaration must be a '
            . \Whity\Sdk\Health\HealthProbeDefinition::class
            . ', got ' . get_debug_type($definition)
        );
    }

    /**
     * The namespaced key is wider than `health_samples.component`, so every
     * sample it produced would fail to store — silently, since the collector
     * cannot treat a write failure as fatal.
     */
    public static function forOversizedKey(string $key): self
    {
        return new self(
            "Health probe key '{$key}' is longer than "
            . HealthProbeRegistry::MAX_KEY_LENGTH
            . ' characters once namespaced, so its samples could never be stored'
        );
    }

    /**
     * The same key twice in one declaration: which of the two callables is
     * meant is unanswerable, so the whole declaration is refused rather than
     * silently keeping one.
     */
    public static function forDuplicateKey(string $key): self
    {
        return new self(
            "Health probe key '{$key}' is declared more than once in the same "
            . 'declaration; each key may appear only once'
        );
    }
}
