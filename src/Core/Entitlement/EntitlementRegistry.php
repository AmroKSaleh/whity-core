<?php

declare(strict_types=1);

namespace Whity\Core\Entitlement;

/**
 * Single source of truth for the platform's per-tenant ENTITLEMENTS (WC-ent).
 *
 * An entitlement is an operator-granted capability or limit for one tenant —
 * "may this tenant use feature X?" (a bool feature flag) or "how much of Y may
 * it use?" (an int quota/limit). Entitlements are how the platform owner sells
 * tiers: a tenant on the free plan is not entitled to a custom storage backend
 * or its own SSO IdP; a paid tenant is. They GATE what a tenant may configure —
 * they are NOT settings the tenant edits.
 *
 * This mirrors {@see \Whity\Core\Settings\SettingsRegistry}: the catalogue lives
 * in code (no migration to add a key), values persist as TEXT, and this registry
 * — not the DB — owns the typed contract. The precedence at runtime is simply
 * `tenant_entitlements[key] ?? EntitlementRegistry::default(key)` (there is no
 * global override layer — the DEFAULT is the baseline/free-tier grant). The
 * SYSTEM tenant (id 0) is implicitly unlimited and has no stored overrides.
 *
 * Two value kinds:
 *   - `bool`  feature flag — granted / not granted.
 *   - `int`   quota / limit — a non-negative cap, or the sentinel -1 = UNLIMITED.
 *
 * Adding an entitlement: declare a `const`, add it to {@see DEFAULTS} and
 * {@see TYPES} with a {@see DESCRIPTIONS} entry. Enforcement lives in the
 * consuming feature (e.g. the storage layer reads STORAGE_CUSTOM_BACKEND); this
 * registry only defines the contract.
 */
final class EntitlementRegistry
{
    /** Non-negative int limits use this sentinel to mean "no cap". */
    public const UNLIMITED = -1;

    // ── Storage ──────────────────────────────────────────────────────────────
    /** May the tenant configure a non-default (own) storage backend? */
    public const STORAGE_CUSTOM_BACKEND = 'storage.custom_backend';
    /** Max total stored bytes for the tenant (-1 = unlimited). */
    public const STORAGE_QUOTA_BYTES = 'storage.quota_bytes';

    // ── Identity / SSO ───────────────────────────────────────────────────────
    /** May the tenant configure its own bring-your-own SSO/OIDC provider? */
    public const SSO_TENANT_IDP = 'sso.tenant_idp';

    // ── Membership ───────────────────────────────────────────────────────────
    /** Max active members the tenant may hold (-1 = unlimited). */
    public const MEMBERS_MAX = 'members.max';

    /**
     * The default (baseline / free-tier) grant for every known entitlement,
     * as its TEXT representation. This is what a tenant gets when the operator
     * has set no override. Order defines catalogue order.
     *
     * @var array<string, string>
     */
    private const DEFAULTS = [
        self::STORAGE_CUSTOM_BACKEND => 'false',
        self::STORAGE_QUOTA_BYTES    => '-1',
        self::SSO_TENANT_IDP         => 'false',
        self::MEMBERS_MAX            => '-1',
    ];

    /**
     * The value kind of each entitlement: 'bool' (feature flag) or 'int'
     * (quota/limit). Must have exactly the same keys as {@see DEFAULTS}.
     *
     * @var array<string, string>
     */
    private const TYPES = [
        self::STORAGE_CUSTOM_BACKEND => 'bool',
        self::STORAGE_QUOTA_BYTES    => 'int',
        self::SSO_TENANT_IDP         => 'bool',
        self::MEMBERS_MAX            => 'int',
    ];

    /**
     * Human-readable description per entitlement (for the operator admin UI).
     *
     * @var array<string, string>
     */
    private const DESCRIPTIONS = [
        self::STORAGE_CUSTOM_BACKEND => 'Allow the tenant to configure its own storage backend (e.g. S3, Google Drive) instead of the platform default.',
        self::STORAGE_QUOTA_BYTES    => 'Maximum total bytes the tenant may store (-1 for unlimited).',
        self::SSO_TENANT_IDP         => 'Allow the tenant to configure its own bring-your-own SSO/OIDC identity provider.',
        self::MEMBERS_MAX            => 'Maximum number of active members the tenant may have (-1 for unlimited).',
    ];

    /**
     * The known entitlement keys, in catalogue order.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }

    public static function isKnown(string $key): bool
    {
        return array_key_exists($key, self::DEFAULTS);
    }

    /**
     * The TEXT default (baseline grant) for a key.
     *
     * @throws \InvalidArgumentException When the key is unknown.
     */
    public static function defaultFor(string $key): string
    {
        self::assertKnown($key);

        return self::DEFAULTS[$key];
    }

    /**
     * The value kind: 'bool' or 'int'.
     *
     * @throws \InvalidArgumentException When the key is unknown.
     */
    public static function typeFor(string $key): string
    {
        self::assertKnown($key);

        return self::TYPES[$key];
    }

    /**
     * The operator-facing description for a key.
     *
     * @throws \InvalidArgumentException When the key is unknown.
     */
    public static function describe(string $key): string
    {
        self::assertKnown($key);

        return self::DESCRIPTIONS[$key];
    }

    /**
     * The full catalogue for the operator admin UI: every key mapped to its
     * type, baseline default, and description. Lets the client render an editor
     * (checkbox for bool, number for int) and show the free-tier baseline.
     *
     * @return array<string, array{type: string, default: string, description: string}>
     */
    public static function catalogue(): array
    {
        $out = [];
        foreach (self::keys() as $key) {
            $out[$key] = [
                'type'        => self::TYPES[$key],
                'default'     => self::DEFAULTS[$key],
                'description' => self::DESCRIPTIONS[$key],
            ];
        }

        return $out;
    }

    /**
     * Validate a raw TEXT value for a key. Returns null when valid, or a
     * human-readable reason string otherwise. Never throws (mirrors
     * SettingsRegistry::validate) so the API layer can surface a 422.
     */
    public static function validate(string $key, string $value): ?string
    {
        if (!self::isKnown($key)) {
            return "Unknown entitlement key: {$key}";
        }

        return match (self::TYPES[$key]) {
            'bool' => self::isBoolLiteral($value)
                ? null
                : "{$key} must be a boolean (true/false).",
            'int' => self::isIntLiteral($value)
                ? (self::normalizeInt($value) >= self::UNLIMITED
                    ? null
                    : "{$key} must be -1 (unlimited) or a non-negative integer.")
                : "{$key} must be an integer.",
        };
    }

    /**
     * Canonicalise a validated value for storage: bool → 'true'/'false',
     * int → its decimal string. Assumes the value already passed validate().
     */
    public static function normalize(string $key, string $value): string
    {
        return match (self::TYPES[$key]) {
            'bool' => self::isTruthy($value) ? 'true' : 'false',
            'int'  => (string) self::normalizeInt($value),
        };
    }

    /**
     * Cast a stored (or default) TEXT value to its typed PHP value:
     * bool → bool, int → int.
     *
     * @throws \InvalidArgumentException When the key is unknown.
     */
    public static function cast(string $key, string $value): bool|int
    {
        self::assertKnown($key);

        return match (self::TYPES[$key]) {
            'bool' => self::isTruthy($value),
            'int'  => self::normalizeInt($value),
        };
    }

    private static function assertKnown(string $key): void
    {
        if (!self::isKnown($key)) {
            throw new \InvalidArgumentException("Unknown entitlement key: {$key}");
        }
    }

    private static function isBoolLiteral(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['true', 'false', '1', '0', 'yes', 'no'], true);
    }

    private static function isTruthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['true', '1', 'yes'], true);
    }

    private static function isIntLiteral(string $value): bool
    {
        return preg_match('/^-?\d+$/', trim($value)) === 1;
    }

    private static function normalizeInt(string $value): int
    {
        return (int) trim($value);
    }
}
