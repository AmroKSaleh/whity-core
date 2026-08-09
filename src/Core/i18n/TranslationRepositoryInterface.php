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

    /**
     * Find a single translation row by its id, regardless of tenant scope.
     *
     * This is the UNSCOPED admin guard lookup: the caller determines whether
     * the row's `tenant_id` is writable by them (see the write-access rule in
     * {@see \Whity\Api\TranslationsApiHandler}) before issuing any scoped
     * mutation — the same pattern as {@see \Whity\Api\RolesApiHandler}'s
     * global-vs-tenant guard.
     *
     * @param int $id The translation id.
     * @return Translation|null The row, or null when no row matches.
     */
    public function findById(int $id): ?Translation;

    /**
     * Create a translation row.
     *
     * @param int      $languageId  The language ID.
     * @param string   $domain      The translation domain (e.g., 'common', 'email').
     * @param string   $key         The translation key.
     * @param string   $translation The translated text.
     * @param int|null $tenantId    NULL for a system default, or the owning tenant
     *                              for a tenant override.
     * @return Translation|null The created row, or null when a row already
     *                          exists for this (language, domain, key, tenant scope)
     *                          — the caller returns 409.
     */
    public function create(int $languageId, string $domain, string $key, string $translation, ?int $tenantId): ?Translation;

    /**
     * Update a translation row's text, scoped to the EXPECTED tenant (NULL for
     * the system default, or the caller's own tenant id for an override) so the
     * mutating statement itself — not just an earlier guard read — rejects a
     * cross-scope id (WC-190 defense-in-depth).
     *
     * @param int      $id              The translation id.
     * @param string   $translation     The new translated text.
     * @param int|null $expectedTenantId NULL to match a system-default row, or the
     *                                   tenant id to match an override row.
     * @return bool True when a row matched and was updated.
     */
    public function update(int $id, string $translation, ?int $expectedTenantId): bool;

    /**
     * Delete a translation row, scoped to the EXPECTED tenant (see {@see self::update()}).
     *
     * @param int      $id              The translation id.
     * @param int|null $expectedTenantId NULL to match a system-default row, or the
     *                                   tenant id to match an override row.
     * @return bool True when a row matched and was deleted.
     */
    public function delete(int $id, ?int $expectedTenantId): bool;
}
