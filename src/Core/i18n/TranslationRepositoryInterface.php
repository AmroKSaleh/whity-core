<?php

declare(strict_types=1);

namespace Whity\Core\i18n;

/**
 * Interface for Translation repository.
 *
 * Provides read access to translations from the database. Translations are
 * tenant-scoped: NULL tenant_id = system default, tenant_id>0 = tenant override.
 */
interface TranslationRepositoryInterface
{
    /**
     * Get all translations for a language and domain, optionally for a specific tenant.
     *
     * Applies the fallback chain: tenant override → system default.
     * Returns both tenant-specific and system default translations, with tenant
     * overrides taking precedence in the returned array (keys are the same,
     * values differ only if there's an override).
     *
     * @param int    $languageId The language ID.
     * @param string $domain     The translation domain (e.g., 'common', 'email').
     * @param int|null $tenantId The tenant ID for overrides, or null for system defaults only.
     * @return Translation[] Translations indexed by key.
     */
    public function findByLanguageAndDomain(int $languageId, string $domain, ?int $tenantId = null): array;

    /**
     * Get all system default translations for a language.
     *
     * @param int $languageId The language ID.
     * @return array<string, array<string, Translation>> Translations indexed by domain then key.
     */
    public function findAllSystemDefaults(int $languageId): array;

    /**
     * Get all tenant override translations for a language and tenant.
     *
     * @param int $languageId The language ID.
     * @param int $tenantId   The tenant ID.
     * @return array<string, array<string, Translation>> Translations indexed by domain then key.
     */
    public function findAllTenantOverrides(int $languageId, int $tenantId): array;
}
