<?php

declare(strict_types=1);

namespace Whity\Core\Settings;

use InvalidArgumentException;

/**
 * Raised when a plugin's setting declaration cannot be accepted by
 * {@see PluginSettingsRegistry} (#713 item 1).
 *
 * Rejection is per setting: one malformed entry does not discard the plugin's
 * other keys, but a malformed entry is never partially accepted. A half-stored
 * definition is the exact failure this whole change exists to remove — a key
 * that looks declared, accepts writes, and validates nothing.
 *
 * Every message names the offending key and says what shape was expected, because
 * the plugin author reading it is looking at a warning line in the host's log,
 * not at a stack trace in their own IDE.
 */
class InvalidSettingDeclarationException extends InvalidArgumentException
{
    /**
     * A bare key that is not a lowercase, dot-groupable identifier.
     *
     * @param string $bareKey The offending key.
     */
    public static function forKey(string $bareKey): self
    {
        return new self(
            "Invalid setting key '{$bareKey}': expected lowercase letters, digits and "
            . 'underscores, starting with a letter, optionally grouped with dots '
            . "('smtp.host') — and no colon, which is the namespace separator the host adds"
        );
    }

    /**
     * A source name from which no usable namespace prefix could be derived.
     *
     * @param string $source The unusable source name.
     */
    public static function forSource(string $source): self
    {
        return new self(
            "Source '{$source}' yields no usable namespace prefix; a settings source "
            . 'must start with a letter'
        );
    }

    /**
     * A canonical key longer than `setting_key VARCHAR(100)` in both settings
     * tables (migrations 024/025).
     *
     * Refused at declaration rather than at write time: the column is the same
     * one core's keys live in, and an over-long key would declare cleanly, appear
     * on the admin screen, and then fail on every single write forever.
     *
     * @param string $key The over-long canonical key.
     */
    public static function forOversizedKey(string $key): self
    {
        return new self(
            "Setting key '{$key}' is " . strlen($key) . ' characters; the settings tables '
            . 'store keys in a VARCHAR(' . PluginSettingsRegistry::MAX_KEY_LENGTH . ') column, '
            . 'so it could never be written. Shorten the key or the plugin name.'
        );
    }

    /**
     * A declaration that is not an array of fields.
     *
     * @param string $key The canonical key whose declaration was malformed.
     */
    public static function forMalformedDeclaration(string $key): self
    {
        return new self("Setting '{$key}': the declaration must be an array of fields");
    }

    /**
     * A missing or unrecognised `type`.
     *
     * @param string $key  The canonical key.
     * @param string $type The offending type.
     */
    public static function forType(string $key, string $type): self
    {
        return new self(
            "Setting '{$key}': 'type' must be one of "
            . implode(', ', SettingDefinition::TYPES) . "; got '{$type}'"
        );
    }

    /**
     * A field whose value is the wrong shape.
     *
     * @param string $key    The canonical key.
     * @param string $field  The declaration field at fault.
     * @param string $detail What was expected.
     */
    public static function forField(string $key, string $field, string $detail): self
    {
        return new self("Setting '{$key}': '{$field}' {$detail}");
    }

    /**
     * A `default` that fails the declaration's own validation rules.
     *
     * The loudest failure in the file, and deliberately so. A default is what the
     * key resolves to when nothing is stored — which is its state on every fresh
     * install and after every clear. If it does not satisfy the constraints the
     * same declaration imposes, the key is born invalid and cannot be reset to
     * anything the host will accept.
     *
     * @param string $key    The canonical key.
     * @param string $reason The validation failure the default itself produced.
     */
    public static function forInvalidDefault(string $key, string $reason): self
    {
        return new self(
            "Setting '{$key}': the declared 'default' does not satisfy this "
            . "declaration's own rules — {$reason} A default that fails its own "
            . 'validation is a key that can never be reset.'
        );
    }

    /**
     * A declaration attempting to store a credential.
     *
     * Refused rather than downgraded to a plain string. Settings are stored as
     * readable TEXT and served by a read API gated on `settings:read`; a
     * credential placed here would be retrievable by everyone holding it. Core
     * keeps its own credentials (the SMTP password, the error-tracking DSN)
     * encrypted and write-only behind dedicated endpoints for the same reason.
     *
     * @param string $key The canonical key.
     */
    public static function forSecret(string $key): self
    {
        return new self(
            "Setting '{$key}': secret-shaped settings are not supported. The settings "
            . 'tables store readable TEXT and the settings API returns it to anyone '
            . 'holding settings:read, so a credential declared here would be readable. '
            . 'Store it encrypted and write-only behind your own route and expose only '
            . 'its presence (has_*), the way core stores the SMTP password and the '
            . 'error-tracking DSN.'
        );
    }

    /**
     * A plugin key that would collide with a core key.
     *
     * Structurally unreachable — a canonical plugin key always carries a `:` and
     * no core key does — and checked anyway, because "structurally unreachable"
     * is a property of today's core catalogue rather than a rule anything
     * enforces, and the failure it would cause is a plugin silently taking over
     * `mail.transport`.
     *
     * @param string $key The contested canonical key.
     */
    public static function forCoreKey(string $key): self
    {
        return new self(
            "Setting '{$key}' is a core setting key and cannot be declared by a plugin"
        );
    }

    /**
     * Two sources (or one source twice) claiming the same canonical key.
     *
     * @param string $key The contested canonical key.
     */
    public static function forDuplicateKey(string $key): self
    {
        return new self("Setting '{$key}' is already registered");
    }
}
