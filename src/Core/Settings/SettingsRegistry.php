<?php

declare(strict_types=1);

namespace Whity\Core\Settings;

use DateTimeZone;

/**
 * The code registry of known website-settings keys (Website Settings feature).
 *
 * This class is the single source of truth for which setting keys exist, how
 * each value is validated, and the hardcoded fallback default used when neither
 * a per-tenant override nor a global default is stored. Values are persisted as
 * TEXT in the `app_settings` (global) and `tenant_settings` (per-tenant) tables;
 * the registry — not the schema — defines the typed contract, so a new key is
 * added here WITHOUT a migration.
 *
 * Known keys and their contracts:
 *  - `site_name`     non-empty string, <= 120 chars                 default "Whity"
 *  - `timezone`      a valid IANA tz id (DateTimeZone::listIdentifiers) default "UTC"
 *  - `locale`        BCP-47-ish short code `^[a-z]{2}(-[A-Z]{2})?$`  default "en"
 *  - `support_email` an RFC-valid email, OR empty to unset          default ""
 *
 * An unknown key is rejected (the service surfaces a 422). Validation never
 * throws on bad input — it returns a human-readable reason string the API layer
 * relays as a field detail.
 *
 * Stateless and side-effect free: safe to call from a FrankenPHP worker; holds
 * no request state.
 */
final class SettingsRegistry
{
    public const SITE_NAME = 'site_name';
    public const TIMEZONE = 'timezone';
    public const LOCALE = 'locale';
    public const SUPPORT_EMAIL = 'support_email';

    public const BRANDING_LOGO_WIDE = 'branding_logo_wide';
    public const BRANDING_LOGO_SQUARE = 'branding_logo_square';
    public const BRANDING_FAVICON = 'branding_favicon';

    /**
     * The asset-kind keys (Tenant Branding). Their stored value is a storage
     * key (or '' when unset). They are NEVER writable via the text PATCH path —
     * uploads go through BrandingService and the binary endpoints.
     *
     * @var list<string>
     */
    private const ASSET_KEYS = [
        self::BRANDING_LOGO_WIDE,
        self::BRANDING_LOGO_SQUARE,
        self::BRANDING_FAVICON,
    ];

    /**
     * Maximum length of the site name, in characters.
     */
    private const SITE_NAME_MAX = 120;

    /**
     * Hardcoded fallback defaults, keyed by setting key.
     *
     * @var array<string, string>
     */
    private const DEFAULTS = [
        self::SITE_NAME => 'Whity',
        self::TIMEZONE => 'UTC',
        self::LOCALE => 'en',
        self::SUPPORT_EMAIL => '',
        self::BRANDING_LOGO_WIDE => '',
        self::BRANDING_LOGO_SQUARE => '',
        self::BRANDING_FAVICON => '',
    ];

    /**
     * Static catalogue only — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * The known setting keys, in declared order.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /**
     * Whether the given key is a known setting key.
     */
    public static function isKnown(string $key): bool
    {
        return array_key_exists($key, self::DEFAULTS);
    }

    /**
     * The hardcoded fallback default for a known key.
     *
     * @throws \InvalidArgumentException When the key is unknown.
     */
    public static function defaultFor(string $key): string
    {
        if (!self::isKnown($key)) {
            throw new \InvalidArgumentException("Unknown setting key: {$key}");
        }

        return self::DEFAULTS[$key];
    }

    /**
     * The full map of defaults (key => default value).
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * The kind of a known key: 'asset' for the branding logo/favicon keys
     * (binary, set only via BrandingService), 'text' for everything else.
     *
     * @throws \InvalidArgumentException When the key is unknown.
     */
    public static function kindFor(string $key): string
    {
        if (!self::isKnown($key)) {
            throw new \InvalidArgumentException("Unknown setting key: {$key}");
        }

        return in_array($key, self::ASSET_KEYS, true) ? 'asset' : 'text';
    }

    /**
     * The simple value-type of a known key: 'asset' for branding binary keys,
     * 'string' for text keys. Exposed so the API can publish the registry
     * shape to the client.
     *
     * @throws \InvalidArgumentException When the key is unknown.
     */
    public static function typeFor(string $key): string
    {
        return self::kindFor($key) === 'asset' ? 'asset' : 'string';
    }

    /**
     * The registry descriptor list the API publishes alongside effective values:
     * one entry per key with its type and default.
     *
     * @return list<array{key: string, type: string, default: string}>
     */
    public static function describe(): array
    {
        $descriptors = [];
        foreach (self::keys() as $key) {
            $descriptors[] = [
                'key' => $key,
                'type' => self::typeFor($key),
                'default' => self::defaultFor($key),
            ];
        }

        return $descriptors;
    }

    /**
     * Validate a value for a known key.
     *
     * Returns null when valid, or a human-readable reason string when invalid.
     * An unknown key is itself a validation failure.
     *
     * @param string $key   The setting key.
     * @param string $value The candidate value (TEXT; the caller stringifies).
     * @return string|null Null when valid; otherwise the failure reason.
     */
    public static function validate(string $key, string $value): ?string
    {
        if (self::isKnown($key) && self::kindFor($key) === 'asset') {
            return "{$key} is an uploaded asset and cannot be set as text; use the branding upload endpoint.";
        }

        return match ($key) {
            self::SITE_NAME => self::validateSiteName($value),
            self::TIMEZONE => self::validateTimezone($value),
            self::LOCALE => self::validateLocale($value),
            self::SUPPORT_EMAIL => self::validateSupportEmail($value),
            default => "Unknown setting key: {$key}",
        };
    }

    /**
     * Normalise a value for a known key into its canonical stored form.
     *
     * This is the value that is actually persisted, so a caller's incidental
     * formatting can never leak into storage. `site_name` is trimmed of
     * surrounding whitespace (so `" Acme "` is stored as `"Acme"`); the other
     * keys are stored verbatim. Callers MUST {@see validate()} first — normalise
     * does not validate, and an unknown key is returned unchanged.
     *
     * @param string $key   The setting key.
     * @param string $value The validated candidate value.
     * @return string The canonical value to persist.
     */
    public static function normalize(string $key, string $value): string
    {
        return match ($key) {
            self::SITE_NAME => trim($value),
            default => $value,
        };
    }

    private static function validateSiteName(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return 'site_name must not be empty.';
        }
        // The trimmed value is what gets stored ({@see normalize()}), so the
        // <= 120 limit applies to it. Count characters (not bytes) so the limit
        // is multibyte-correct.
        if (mb_strlen($trimmed) > self::SITE_NAME_MAX) {
            return 'site_name must be at most ' . self::SITE_NAME_MAX . ' characters.';
        }

        return null;
    }

    private static function validateTimezone(string $value): ?string
    {
        if (!in_array($value, DateTimeZone::listIdentifiers(), true)) {
            return 'timezone must be a valid IANA time zone identifier.';
        }

        return null;
    }

    private static function validateLocale(string $value): ?string
    {
        if (preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $value) !== 1) {
            return 'locale must match the pattern ^[a-z]{2}(-[A-Z]{2})?$ (e.g. "en" or "en-US").';
        }

        return null;
    }

    private static function validateSupportEmail(string $value): ?string
    {
        // Empty is the explicit "unset" value for support_email.
        if ($value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return 'support_email must be a valid email address (or empty).';
        }

        return null;
    }
}
