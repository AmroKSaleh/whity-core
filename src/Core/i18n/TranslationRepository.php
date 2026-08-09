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

    /**
     * Find a single translation row by its id, regardless of tenant scope.
     *
     * Unscoped by design — this is the admin guard lookup consumed by
     * {@see \Whity\Api\TranslationsApiHandler} to decide write-manageability
     * BEFORE any scoped mutation runs.
     *
     * @tenant-guard-ignore: unscoped admin guard lookup; the caller checks the
     * returned row's tenant_id against its own identity before any scoped
     * UPDATE/DELETE executes (see TranslationsApiHandler::writeAccessFor()).
     */
    public function findById(int $id): ?Translation
    {
        $stmt = $this->pdo->prepare('SELECT * FROM translations WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Translation::fromRow($row) : null;
    }

    /**
     * Create a translation row. Pre-checks for an existing row in the same
     * scope before inserting: the UNIQUE(language_id, domain, key, tenant_id)
     * constraint does NOT catch two NULL-tenant (system default) duplicates,
     * since SQL treats NULL as distinct from NULL for uniqueness purposes —
     * the explicit check covers that case, and the constraint remains a
     * defence-in-depth backstop for the non-NULL (tenant override) case.
     *
     * @return Translation|null The created row, or null on a duplicate (409).
     */
    public function create(int $languageId, string $domain, string $key, string $translation, ?int $tenantId): ?Translation
    {
        if ($tenantId === null) {
            $existsStmt = $this->pdo->prepare(
                'SELECT 1 FROM translations
                 WHERE language_id = :language_id AND domain = :domain AND key = :key AND tenant_id IS NULL'
            );
            $existsStmt->execute([':language_id' => $languageId, ':domain' => $domain, ':key' => $key]);
        } else {
            $existsStmt = $this->pdo->prepare(
                'SELECT 1 FROM translations
                 WHERE language_id = :language_id AND domain = :domain AND key = :key AND tenant_id = :tenant_id'
            );
            $existsStmt->execute([
                ':language_id' => $languageId,
                ':domain' => $domain,
                ':key' => $key,
                ':tenant_id' => $tenantId,
            ]);
        }
        if ($existsStmt->fetch() !== false) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO translations (language_id, domain, key, translation, tenant_id, created_at, updated_at)
                 VALUES (:language_id, :domain, :key, :translation, :tenant_id, NOW(), NOW())'
            );
            $stmt->execute([
                ':language_id' => $languageId,
                ':domain' => $domain,
                ':key' => $key,
                ':translation' => $translation,
                ':tenant_id' => $tenantId,
            ]);

            return $this->findById((int) $this->pdo->lastInsertId());
        } catch (\PDOException $e) {
            if (self::isUniqueViolation($e)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Update a translation row's text, scoped to the EXPECTED tenant so the
     * mutating statement itself carries the tenant predicate (WC-190
     * defense-in-depth) — not merely an earlier guard read.
     */
    public function update(int $id, string $translation, ?int $expectedTenantId): bool
    {
        if ($expectedTenantId === null) {
            $stmt = $this->pdo->prepare(
                'UPDATE translations SET translation = :translation, updated_at = NOW()
                 WHERE id = :id AND tenant_id IS NULL'
            );
            $stmt->execute([':translation' => $translation, ':id' => $id]);
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE translations SET translation = :translation, updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tenant_id'
            );
            $stmt->execute([':translation' => $translation, ':id' => $id, ':tenant_id' => $expectedTenantId]);
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a translation row, scoped to the EXPECTED tenant (see {@see self::update()}).
     */
    public function delete(int $id, ?int $expectedTenantId): bool
    {
        if ($expectedTenantId === null) {
            $stmt = $this->pdo->prepare('DELETE FROM translations WHERE id = :id AND tenant_id IS NULL');
            $stmt->execute([':id' => $id]);
        } else {
            $stmt = $this->pdo->prepare('DELETE FROM translations WHERE id = :id AND tenant_id = :tenant_id');
            $stmt->execute([':id' => $id, ':tenant_id' => $expectedTenantId]);
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Whether a PDOException was raised by a UNIQUE-constraint violation
     * (Postgres 23505, or the "UNIQUE constraint failed" SQLite message).
     */
    private static function isUniqueViolation(\PDOException $e): bool
    {
        return $e->getCode() === '23505' || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
