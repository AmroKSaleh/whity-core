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

    // MCP feature flag (WC-149b2fc9). Value is the literal string 'true' or
    // 'false'; an admin sets it per-tenant to enable the MCP endpoint.
    public const MCP_ENABLED = 'mcp.enabled';

    // Instance-governance flags (WC-696206d8). Global/operator-level: control
    // self-service signup for the whole instance. Literal 'true'/'false'.
    //   - self_registration_enabled: is the public POST /api/register open at all?
    //     Default 'false' (CLOSED) — a sovereign instance is operator-provisioned;
    //     the operator opens signup explicitly via instance settings.
    //   - registration_approval_required: when signup IS open, is a new owner
    //     membership created 'invited' (pending admin approval) instead of active?
    //     Default 'true' (approval required).
    public const SELF_REGISTRATION_ENABLED = 'auth.self_registration_enabled';
    public const REGISTRATION_APPROVAL_REQUIRED = 'auth.registration_approval_required';

    // Instance SSO kill-switch (WC-28fb2e19). Global/operator-level: when 'false',
    // federated sign-in is disabled instance-wide (both operator and tenant IdPs).
    // Default 'true' — SSO is available where a provider is configured.
    public const SSO_ENABLED = 'auth.sso_enabled';

    // Storage backend selection + S3-compatible config (WC-b8c5a271 / WC-28fb2e19).
    // Global/operator-level. `storage.driver` selects local (default) or s3; the
    // s3.* keys configure the bucket. The S3 SECRET KEY is NOT a setting — it is
    // sourced from the STORAGE_S3_SECRET_KEY env (deployment secret), never stored
    // in app_settings nor exposed on the settings API.
    public const STORAGE_DRIVER = 'storage.driver';
    public const STORAGE_S3_ENDPOINT = 'storage.s3.endpoint';
    public const STORAGE_S3_REGION = 'storage.s3.region';
    public const STORAGE_S3_BUCKET = 'storage.s3.bucket';
    public const STORAGE_S3_ACCESS_KEY = 'storage.s3.access_key';
    public const STORAGE_S3_PATH_STYLE = 'storage.s3.path_style';
    public const STORAGE_S3_PUBLIC_BASE_URL = 'storage.s3.public_base_url';

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
     * GLOBAL-ONLY keys (WC-696206d8 / instance governance): operator-level flags
     * that have meaning ONLY at the global layer and are enforced from it. They
     * are NOT per-tenant-overridable — a tenant admin must never set them (a
     * per-tenant value would be inert and misleading). Exposed + writable ONLY on
     * the global settings surface (/api/settings/global, SETTINGS_MANAGE), and
     * excluded from the per-tenant settings surface.
     *
     * @var list<string>
     */
    private const GLOBAL_ONLY_KEYS = [
        self::SELF_REGISTRATION_ENABLED,
        self::REGISTRATION_APPROVAL_REQUIRED,
        self::SSO_ENABLED,
        self::STORAGE_DRIVER,
        self::STORAGE_S3_ENDPOINT,
        self::STORAGE_S3_REGION,
        self::STORAGE_S3_BUCKET,
        self::STORAGE_S3_ACCESS_KEY,
        self::STORAGE_S3_PATH_STYLE,
        self::STORAGE_S3_PUBLIC_BASE_URL,
    ];

    /**
     * Boolean-valued keys (literal 'true'/'false'). Reported with type 'bool' so
     * clients render a toggle instead of a text input.
     *
     * @var list<string>
     */
    private const BOOL_KEYS = [
        self::MCP_ENABLED,
        self::SELF_REGISTRATION_ENABLED,
        self::REGISTRATION_APPROVAL_REQUIRED,
        self::SSO_ENABLED,
        self::STORAGE_S3_PATH_STYLE,
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
        self::MCP_ENABLED => 'false',
        // Secure-by-default: signup CLOSED, approval REQUIRED when opened.
        self::SELF_REGISTRATION_ENABLED => 'false',
        self::REGISTRATION_APPROVAL_REQUIRED => 'true',
        self::SSO_ENABLED => 'true',
        self::STORAGE_DRIVER => 'local',
        self::STORAGE_S3_ENDPOINT => '',
        self::STORAGE_S3_REGION => '',
        self::STORAGE_S3_BUCKET => '',
        self::STORAGE_S3_ACCESS_KEY => '',
        self::STORAGE_S3_PATH_STYLE => 'true',
        self::STORAGE_S3_PUBLIC_BASE_URL => '',
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
     * The text-kind setting keys only (excludes asset-kind keys).
     *
     * Use this when building the settings API surface so that branding asset
     * keys — managed via the branding endpoints — are never exposed on the
     * text settings endpoints.
     *
     * @return list<string>
     */
    public static function textKeys(): array
    {
        return array_values(array_filter(
            self::keys(),
            static fn (string $k): bool => !in_array($k, self::ASSET_KEYS, true)
        ));
    }

    /**
     * Like {@see describe()} but restricted to text-kind keys only.
     *
     * Intended for the settings API handler so asset-kind keys are not published
     * on the GET /api/v1/settings surface.
     *
     * @return list<array{key: string, type: string, default: string}>
     */
    public static function describeText(): array
    {
        $descriptors = [];
        foreach (self::textKeys() as $key) {
            $descriptors[] = [
                'key' => $key,
                'type' => self::typeFor($key),
                'default' => self::defaultFor($key),
            ];
        }

        return $descriptors;
    }

    /**
     * Whether the given key is a known setting key.
     */
    public static function isKnown(string $key): bool
    {
        return array_key_exists($key, self::DEFAULTS);
    }

    /**
     * Whether the key is GLOBAL-ONLY (operator governance): settable only on the
     * global settings surface, never as a per-tenant override.
     */
    public static function isGlobalOnly(string $key): bool
    {
        return in_array($key, self::GLOBAL_ONLY_KEYS, true);
    }

    /**
     * The text-kind keys that a TENANT may override — text keys minus the
     * global-only governance keys. Drives the per-tenant settings surface.
     *
     * @return list<string>
     */
    public static function tenantTextKeys(): array
    {
        return array_values(array_filter(
            self::textKeys(),
            static fn (string $k): bool => !self::isGlobalOnly($k)
        ));
    }

    /**
     * Like {@see describeText()} but restricted to the tenant-overridable keys
     * (excludes global-only governance keys) for the per-tenant settings API.
     *
     * @return list<array{key: string, type: string, default: string}>
     */
    public static function describeTenantText(): array
    {
        $descriptors = [];
        foreach (self::tenantTextKeys() as $key) {
            $descriptors[] = [
                'key' => $key,
                'type' => self::typeFor($key),
                'default' => self::defaultFor($key),
            ];
        }

        return $descriptors;
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
        if (self::kindFor($key) === 'asset') {
            return 'asset';
        }
        // Boolean flags report 'bool' so clients render a toggle, not a text field.
        return in_array($key, self::BOOL_KEYS, true) ? 'bool' : 'string';
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
            self::MCP_ENABLED => self::validateMcpEnabled($value),
            self::SELF_REGISTRATION_ENABLED => self::validateBoolean($value, self::SELF_REGISTRATION_ENABLED),
            self::REGISTRATION_APPROVAL_REQUIRED => self::validateBoolean($value, self::REGISTRATION_APPROVAL_REQUIRED),
            self::SSO_ENABLED => self::validateBoolean($value, self::SSO_ENABLED),
            self::STORAGE_DRIVER => self::validateStorageDriver($value),
            self::STORAGE_S3_PATH_STYLE => self::validateBoolean($value, self::STORAGE_S3_PATH_STYLE),
            self::STORAGE_S3_ENDPOINT,
            self::STORAGE_S3_REGION,
            self::STORAGE_S3_BUCKET,
            self::STORAGE_S3_ACCESS_KEY,
            self::STORAGE_S3_PUBLIC_BASE_URL => null, // free-form strings (validated at driver build)
            default => "Unknown setting key: {$key}",
        };
    }

    /**
     * Validate a literal boolean setting value ('true' | 'false').
     */
    private static function validateBoolean(string $value, string $key): ?string
    {
        if ($value !== 'true' && $value !== 'false') {
            return "{$key} must be 'true' or 'false'.";
        }

        return null;
    }

    private static function validateStorageDriver(string $value): ?string
    {
        if ($value !== 'local' && $value !== 's3') {
            return "storage.driver must be 'local' or 's3'.";
        }

        return null;
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

    private static function validateMcpEnabled(string $value): ?string
    {
        if ($value !== 'true' && $value !== 'false') {
            return "mcp.enabled must be 'true' or 'false'.";
        }

        return null;
    }
}
