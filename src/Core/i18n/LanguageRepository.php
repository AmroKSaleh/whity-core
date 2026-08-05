<?php

declare(strict_types=1);

namespace Whity\Core\i18n;

use PDO;

/**
 * Repository for Language records.
 *
 * Provides read access to languages from the database. Languages are global
 * (not tenant-scoped) and all tenants can access the same set of language codes.
 */
final class LanguageRepository implements LanguageRepositoryInterface
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * Get all languages, optionally filtered by enabled status.
     *
     * @param bool|null $enabled Filter by enabled status, or null for all.
     * @return Language[] List of Language objects, indexed by code.
     */
    public function findAll(?bool $enabled = null): array
    {
        if ($enabled !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM languages WHERE enabled = :enabled ORDER BY code ASC'
            );
            $stmt->execute([':enabled' => $enabled]);
        } else {
            $stmt = $this->pdo->query('SELECT * FROM languages ORDER BY code ASC');
        }

        $languages = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $language = Language::fromRow($row);
            $languages[$language->code] = $language;
        }

        return $languages;
    }

    /**
     * Get a language by its code.
     *
     * @param string $code The language code (e.g., 'en', 'ar').
     * @return Language|null The Language object, or null if not found.
     */
    public function findByCode(string $code): ?Language
    {
        $stmt = $this->pdo->prepare('SELECT * FROM languages WHERE code = :code');
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Language::fromRow($row) : null;
    }

    /**
     * Get a language by its ID.
     *
     * @param int $id The language ID.
     * @return Language|null The Language object, or null if not found.
     */
    public function findById(int $id): ?Language
    {
        $stmt = $this->pdo->prepare('SELECT * FROM languages WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Language::fromRow($row) : null;
    }
}
