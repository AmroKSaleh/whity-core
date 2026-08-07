<?php

declare(strict_types=1);

namespace Whity\Core\i18n;

use PDO;

/**
 * Repository for Translation records.
 *
 * Provides read access to translations from the database. Translations are
 * tenant-scoped: NULL tenant_id = system default, tenant_id>0 = tenant override.
 */
final class TranslationRepository implements TranslationRepositoryInterface
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

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
    public function findByLanguageAndDomain(int $languageId, string $domain, ?int $tenantId = null): array
    {
        // Get system defaults first.
        $stmt = $this->pdo->prepare(
            'SELECT * FROM translations
             WHERE language_id = :language_id AND domain = :domain AND tenant_id IS NULL
             ORDER BY key ASC'
        );
        $stmt->execute([
            ':language_id' => $languageId,
            ':domain' => $domain,
        ]);

        $translations = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $translation = Translation::fromRow($row);
            $translations[$translation->key] = $translation;
        }

        // If a specific tenant is requested, layer tenant overrides on top.
        if ($tenantId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM translations
                 WHERE language_id = :language_id AND domain = :domain AND tenant_id = :tenant_id
                 ORDER BY key ASC'
            );
            $stmt->execute([
                ':language_id' => $languageId,
                ':domain' => $domain,
                ':tenant_id' => $tenantId,
            ]);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $translation = Translation::fromRow($row);
                $translations[$translation->key] = $translation;
            }
        }

        return $translations;
    }

    /**
     * Get all system default translations for a language.
     *
     * @param int $languageId The language ID.
     * @return array<string, array<string, Translation>> Translations indexed by domain then key.
     */
    public function findAllSystemDefaults(int $languageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM translations
             WHERE language_id = :language_id AND tenant_id IS NULL
             ORDER BY domain ASC, key ASC'
        );
        $stmt->execute([':language_id' => $languageId]);

        $translations = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $translation = Translation::fromRow($row);
            if (!isset($translations[$translation->domain])) {
                $translations[$translation->domain] = [];
            }
            $translations[$translation->domain][$translation->key] = $translation;
        }

        return $translations;
    }

    /**
     * Get all tenant override translations for a language and tenant.
     *
     * @param int $languageId The language ID.
     * @param int $tenantId   The tenant ID.
     * @return array<string, array<string, Translation>> Translations indexed by domain then key.
     */
    public function findAllTenantOverrides(int $languageId, int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM translations
             WHERE language_id = :language_id AND tenant_id = :tenant_id
             ORDER BY domain ASC, key ASC'
        );
        $stmt->execute([
            ':language_id' => $languageId,
            ':tenant_id' => $tenantId,
        ]);

        $translations = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $translation = Translation::fromRow($row);
            if (!isset($translations[$translation->domain])) {
                $translations[$translation->domain] = [];
            }
            $translations[$translation->domain][$translation->key] = $translation;
        }

        return $translations;
    }
}
