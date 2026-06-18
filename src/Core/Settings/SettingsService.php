<?php

declare(strict_types=1);

namespace Whity\Core\Settings;

/**
 * Resolves and mutates website settings across the global + per-tenant layers
 * (Website Settings feature).
 *
 * Resolution precedence for {@see effective()}, per known registry key:
 *   tenant_settings[key] ?? app_settings[key] ?? SettingsRegistry::default(key)
 *
 * The SYSTEM tenant (id 0) has no per-tenant override layer, so its effective
 * values resolve from globals (then registry defaults) only — it skips the
 * tenant layer entirely.
 *
 * All writes are registry-validated before they touch a repository; an invalid
 * value (or an unknown key) raises {@see SettingsValidationException} and
 * nothing is persisted. All SQL goes through the two repositories — the service
 * issues none directly — and the tenant repository binds an explicit `tenant_id`
 * predicate on every statement.
 *
 * Stateless beyond its injected repositories: safe for a FrankenPHP worker.
 */
final class SettingsService
{
    /**
     * The system tenant id; it has globals only (no per-tenant override layer).
     */
    public const SYSTEM_TENANT_ID = 0;

    private GlobalSettingsRepository $globals;
    private TenantSettingsRepository $tenants;

    public function __construct(GlobalSettingsRepository $globals, TenantSettingsRepository $tenants)
    {
        $this->globals = $globals;
        $this->tenants = $tenants;
    }

    /**
     * The effective value map for a tenant: one entry per known registry key,
     * resolved tenant-override → global → registry-default.
     *
     * @param int $tenantId The acting tenant (0 = system → globals only).
     * @return array<string, string> Every known key mapped to its resolved value.
     */
    public function effective(int $tenantId): array
    {
        $global = $this->globals->all();
        $tenant = $tenantId === self::SYSTEM_TENANT_ID ? [] : $this->tenants->allForTenant($tenantId);

        $effective = [];
        foreach (SettingsRegistry::keys() as $key) {
            if (array_key_exists($key, $tenant)) {
                $effective[$key] = $tenant[$key];
            } elseif (array_key_exists($key, $global)) {
                $effective[$key] = $global[$key];
            } else {
                $effective[$key] = SettingsRegistry::defaultFor($key);
            }
        }

        return $effective;
    }

    /**
     * The known keys for which the tenant has an explicit override (i.e. its
     * effective value comes from the tenant layer, not global/default).
     *
     * Empty for the system tenant (it has no override layer).
     *
     * @param int $tenantId The acting tenant.
     * @return list<string> Overridden keys, in registry order.
     */
    public function overriddenKeys(int $tenantId): array
    {
        if ($tenantId === self::SYSTEM_TENANT_ID) {
            return [];
        }

        $tenant = $this->tenants->allForTenant($tenantId);
        $overridden = [];
        foreach (SettingsRegistry::keys() as $key) {
            if (array_key_exists($key, $tenant)) {
                $overridden[] = $key;
            }
        }

        return $overridden;
    }

    /**
     * The stored global defaults: one entry per known registry key, resolved
     * global → registry-default (the per-tenant layer is never consulted).
     *
     * @return array<string, string>
     */
    public function getGlobal(): array
    {
        $global = $this->globals->all();

        $out = [];
        foreach (SettingsRegistry::keys() as $key) {
            $out[$key] = array_key_exists($key, $global)
                ? $global[$key]
                : SettingsRegistry::defaultFor($key);
        }

        return $out;
    }

    /**
     * Set (or clear) a single GLOBAL default, registry-validated.
     *
     * A null value clears the global default (it falls back to the registry
     * default); a non-null value is validated and upserted.
     *
     * @param string      $key   The setting key (must be known).
     * @param string|null $value The value, or null to clear the global default.
     * @throws SettingsValidationException When the key is unknown or the value invalid.
     */
    public function setGlobal(string $key, ?string $value): void
    {
        $this->assertKnown($key);

        if ($value === null) {
            $this->globals->delete($key);
            return;
        }

        $this->assertValid($key, $value);
        $this->globals->set($key, SettingsRegistry::normalize($key, $value));
    }

    /**
     * Set (or clear) a single PER-TENANT override, registry-validated.
     *
     * A null value clears the override (the value falls back to global/default);
     * a non-null value is validated and upserted under the given tenant.
     *
     * The system tenant (0) has no override layer — writing one is rejected so a
     * meaningless row can never be created.
     *
     * @param int         $tenantId The acting tenant.
     * @param string      $key      The setting key (must be known).
     * @param string|null $value    The override value, or null to clear it.
     * @throws SettingsValidationException When the key is unknown, the value invalid,
     *                                     or the tenant is the system tenant.
     */
    public function setTenant(int $tenantId, string $key, ?string $value): void
    {
        $this->assertKnown($key);

        if ($tenantId === self::SYSTEM_TENANT_ID) {
            throw new SettingsValidationException(
                $key,
                'The system tenant has no per-tenant override layer; edit the global default instead.'
            );
        }

        if ($value === null) {
            $this->tenants->delete($tenantId, $key);
            return;
        }

        $this->assertValid($key, $value);
        $this->tenants->set($tenantId, $key, SettingsRegistry::normalize($key, $value));
    }

    /**
     * @throws SettingsValidationException When the key is unknown.
     */
    private function assertKnown(string $key): void
    {
        if (!SettingsRegistry::isKnown($key)) {
            throw new SettingsValidationException($key, "Unknown setting key: {$key}");
        }
    }

    /**
     * @throws SettingsValidationException When the value fails registry validation.
     */
    private function assertValid(string $key, string $value): void
    {
        $reason = SettingsRegistry::validate($key, $value);
        if ($reason !== null) {
            throw new SettingsValidationException($key, $reason);
        }
    }
}
