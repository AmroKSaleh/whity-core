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

    // Email transport + notification settings (WC-email). Global/operator-level:
    // mail is configured once for the whole instance. `mail.transport` selects
    // the backend (none/log/smtp); the smtp.* keys configure the server. The
    // SMTP PASSWORD is deliberately NOT a registry setting — it is stored
    // write-only and encrypted at rest ({@see \Whity\Core\Security\EncryptedSecretStore})
    // under the app_settings key `mail.smtp.password_encrypted`, never exposed on
    // the settings API. The events.* toggles gate the individual customer
    // notifications dispatched from platform hooks.
    public const MAIL_TRANSPORT = 'mail.transport';
    public const MAIL_SMTP_HOST = 'mail.smtp.host';
    public const MAIL_SMTP_PORT = 'mail.smtp.port';
    public const MAIL_SMTP_ENCRYPTION = 'mail.smtp.encryption';
    public const MAIL_SMTP_USERNAME = 'mail.smtp.username';
    public const MAIL_FROM_ADDRESS = 'mail.from_address';
    public const MAIL_FROM_NAME = 'mail.from_name';
    public const MAIL_EVENT_WELCOME = 'mail.events.welcome_enabled';
    public const MAIL_EVENT_APPROVAL = 'mail.events.approval_enabled';
    public const MAIL_EVENT_INVITATION = 'mail.events.invitation_enabled';
    public const MAIL_EVENT_VERIFICATION = 'mail.events.verification_enabled';
    // Account/tenant removal notices — the friendly "sorry to see you go" farewell
    // and the terms-of-service termination notice (both fire on membership removal).
    public const MAIL_EVENT_DELETION = 'mail.events.deletion_enabled';
    // Email TEMPLATE branding (WC-email): the customisation surface for the
    // transactional email layout. brand_color is a #RRGGBB hex; footer_text is a
    // free-form line shown in every message footer.
    public const MAIL_BRAND_COLOR = 'mail.brand_color';
    public const MAIL_FOOTER_TEXT = 'mail.footer_text';

    // Billing / payment-wall governance (WC-billing). Operator-global defaults the
    // per-tenant subscription state falls back to.
    public const BILLING_ENFORCEMENT_DEFAULT = 'billing.enforcement_default';
    public const BILLING_GRACE_DAYS = 'billing.grace_days';

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
        self::MAIL_TRANSPORT,
        self::MAIL_SMTP_HOST,
        self::MAIL_SMTP_PORT,
        self::MAIL_SMTP_ENCRYPTION,
        self::MAIL_SMTP_USERNAME,
        self::MAIL_FROM_ADDRESS,
        self::MAIL_FROM_NAME,
        self::MAIL_EVENT_WELCOME,
        self::MAIL_EVENT_APPROVAL,
        self::MAIL_EVENT_INVITATION,
        self::MAIL_EVENT_VERIFICATION,
        self::MAIL_EVENT_DELETION,
        self::MAIL_BRAND_COLOR,
        self::MAIL_FOOTER_TEXT,
        self::BILLING_ENFORCEMENT_DEFAULT,
        self::BILLING_GRACE_DAYS,
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
        self::MAIL_EVENT_WELCOME,
        self::MAIL_EVENT_APPROVAL,
        self::MAIL_EVENT_INVITATION,
        self::MAIL_EVENT_VERIFICATION,
        self::MAIL_EVENT_DELETION,
    ];

    /**
     * Enum-valued keys and their allowed values. Reported with type 'enum' and an
     * `options` list so clients render a fixed-choice selector.
     *
     * @var array<string, list<string>>
     */
    private const ENUM_OPTIONS = [
        self::MAIL_TRANSPORT => ['none', 'log', 'smtp'],
        self::MAIL_SMTP_ENCRYPTION => ['none', 'tls', 'ssl'],
        // Payment-wall strictness the wall applies to a LAPSED tenant. 'warn' is
        // the safe global default (never blocks); the operator raises it globally
        // or per-tenant. Kept in sync with SubscriptionService enforcement modes.
        self::BILLING_ENFORCEMENT_DEFAULT => ['off', 'warn', 'block_writes', 'block_all'],
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
        // Email off by default (no transport) — the operator configures SMTP and
        // opts in explicitly. STARTTLS on port 587 is the submission default.
        self::MAIL_TRANSPORT => 'none',
        self::MAIL_SMTP_HOST => '',
        self::MAIL_SMTP_PORT => '587',
        self::MAIL_SMTP_ENCRYPTION => 'tls',
        self::MAIL_SMTP_USERNAME => '',
        self::MAIL_FROM_ADDRESS => '',
        self::MAIL_FROM_NAME => '',
        // Notification toggles default ON: once a transport is configured, the
        // standard customer emails fire. (With transport 'none' nothing is sent
        // regardless, so this is safe.)
        self::MAIL_EVENT_WELCOME => 'true',
        self::MAIL_EVENT_APPROVAL => 'true',
        self::MAIL_EVENT_INVITATION => 'true',
        self::MAIL_EVENT_VERIFICATION => 'true',
        self::MAIL_EVENT_DELETION => 'true',
        // Email template branding: on-brand default; operator-overridable.
        self::MAIL_BRAND_COLOR => '#2B6CD2',
        self::MAIL_FOOTER_TEXT => '',
        // Payment wall defaults SAFE: 'warn' never blocks (a fresh/sovereign
        // deploy is never locked out); the operator opts into blocking globally or
        // per-tenant. A past_due tenant keeps access for grace_days days.
        self::BILLING_ENFORCEMENT_DEFAULT => 'warn',
        self::BILLING_GRACE_DAYS => '7',
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
     * @return list<array{key: string, type: string, default: string, options?: list<string>}>
     */
    public static function describeText(): array
    {
        return array_map([self::class, 'descriptorFor'], self::textKeys());
    }

    /**
     * Build the API descriptor for a single key: key + type + default, plus an
     * `options` list for enum-type keys.
     *
     * @return array{key: string, type: string, default: string, options?: list<string>}
     */
    private static function descriptorFor(string $key): array
    {
        $descriptor = [
            'key' => $key,
            'type' => self::typeFor($key),
            'default' => self::defaultFor($key),
        ];

        $options = self::optionsFor($key);
        if ($options !== null) {
            $descriptor['options'] = $options;
        }

        return $descriptor;
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
     * @return list<array{key: string, type: string, default: string, options?: list<string>}>
     */
    public static function describeTenantText(): array
    {
        return array_map([self::class, 'descriptorFor'], self::tenantTextKeys());
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
        // Fixed-choice keys report 'enum' (with options in the descriptor).
        if (array_key_exists($key, self::ENUM_OPTIONS)) {
            return 'enum';
        }
        // Boolean flags report 'bool' so clients render a toggle, not a text field.
        return in_array($key, self::BOOL_KEYS, true) ? 'bool' : 'string';
    }

    /**
     * The allowed values for an enum-type key, or null when the key is not an
     * enum. Published in the API descriptor so clients render a fixed selector.
     *
     * @return list<string>|null
     */
    public static function optionsFor(string $key): ?array
    {
        return self::ENUM_OPTIONS[$key] ?? null;
    }

    /**
     * The registry descriptor list the API publishes alongside effective values:
     * one entry per key with its type and default (plus `options` for enums).
     *
     * @return list<array{key: string, type: string, default: string, options?: list<string>}>
     */
    public static function describe(): array
    {
        return array_map([self::class, 'descriptorFor'], self::keys());
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
            self::MAIL_TRANSPORT,
            self::MAIL_SMTP_ENCRYPTION => self::validateEnum($key, $value),
            self::MAIL_SMTP_PORT => self::validatePort($value),
            self::MAIL_FROM_ADDRESS => self::validateFromAddress($value),
            self::MAIL_EVENT_WELCOME,
            self::MAIL_EVENT_APPROVAL,
            self::MAIL_EVENT_INVITATION,
            self::MAIL_EVENT_VERIFICATION,
            self::MAIL_EVENT_DELETION => self::validateBoolean($value, $key),
            self::BILLING_ENFORCEMENT_DEFAULT => self::validateEnum($key, $value),
            self::BILLING_GRACE_DAYS => self::validateGraceDays($value),
            self::MAIL_BRAND_COLOR => self::validateHexColor($value),
            self::MAIL_SMTP_HOST,
            self::MAIL_SMTP_USERNAME,
            self::MAIL_FROM_NAME,
            self::MAIL_FOOTER_TEXT => null, // free-form strings
            default => "Unknown setting key: {$key}",
        };
    }

    /**
     * Validate a #RRGGBB hex colour.
     */
    private static function validateHexColor(string $value): ?string
    {
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $value) !== 1) {
            return 'mail.brand_color must be a #RRGGBB hex colour (e.g. #2B6CD2).';
        }

        return null;
    }

    /**
     * Validate a value against a key's fixed enum options.
     */
    private static function validateEnum(string $key, string $value): ?string
    {
        $options = self::ENUM_OPTIONS[$key] ?? [];
        if (!in_array($value, $options, true)) {
            return "{$key} must be one of: " . implode(', ', $options) . '.';
        }

        return null;
    }

    /**
     * Validate an SMTP TCP port (1–65535).
     */
    private static function validatePort(string $value): ?string
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            return 'mail.smtp.port must be a whole number.';
        }
        $port = (int) $value;
        if ($port < 1 || $port > 65535) {
            return 'mail.smtp.port must be between 1 and 65535.';
        }

        return null;
    }

    private static function validateGraceDays(string $value): ?string
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            return 'billing.grace_days must be a whole number of days (0 or more).';
        }
        if ((int) $value > 3650) {
            return 'billing.grace_days must be 3650 or fewer.';
        }

        return null;
    }

    /**
     * Validate the From address: a valid email, or empty to leave unconfigured.
     */
    private static function validateFromAddress(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return 'mail.from_address must be a valid email address (or empty).';
        }

        return null;
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
