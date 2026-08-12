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

    /**
     * Create a new language. Returns null (409 to the caller) when a language
     * with this code already exists — the `code` column's UNIQUE constraint is
     * the source of truth; this is a defence against a lost race, not the
     * primary check.
     *
     * @param string $code      The language code (e.g., 'en', 'ar'). Must be unique.
     * @param string $name      The display name (e.g., 'English').
     * @param bool   $enabled   Whether the language is enabled. Defaults to true.
     * @param string $direction The writing direction ('ltr'|'rtl'). Defaults to 'ltr'.
     * @return Language|null The created Language, or null on a duplicate code.
     */
    public function create(
        string $code,
        string $name,
        bool $enabled = true,
        string $direction = Language::DIRECTION_LTR
    ): ?Language {
        // $enabled is a trusted derived boolean; inject as a SQL LITERAL (not a
        // bound param) so it types correctly on both Postgres and the SQLite
        // test engine — mirrors NotificationPreferenceRepository::set().
        $enabledSql = $enabled ? 'TRUE' : 'FALSE';

        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO languages (code, name, enabled, direction, created_at, updated_at)
                 VALUES (:code, :name, {$enabledSql}, :direction, NOW(), NOW())"
            );
            $stmt->execute([
                ':code' => $code,
                ':name' => $name,
                ':direction' => Language::normalizeDirection($direction),
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
     * Update a language's name, enabled status and/or writing direction.
     * Passing null for a parameter leaves that field unchanged.
     *
     * @param int         $id        The language ID.
     * @param string|null $name      The new display name, or null to leave unchanged.
     * @param bool|null   $enabled   The new enabled status, or null to leave unchanged.
     * @param string|null $direction The new direction ('ltr'|'rtl'), or null to leave unchanged.
     * @return Language|null The updated Language, or null when no language matched.
     */
    public function update(int $id, ?string $name, ?bool $enabled, ?string $direction = null): ?Language
    {
        $sets = [];
        $params = [':id' => $id];

        if ($name !== null) {
            $sets[] = 'name = :name';
            $params[':name'] = $name;
        }
        if ($enabled !== null) {
            // Trusted derived boolean injected as a SQL LITERAL — see create().
            $sets[] = 'enabled = ' . ($enabled ? 'TRUE' : 'FALSE');
        }
        if ($direction !== null) {
            $sets[] = 'direction = :direction';
            $params[':direction'] = Language::normalizeDirection($direction);
        }

        if ($sets === []) {
            return $this->findById($id);
        }

        $sets[] = 'updated_at = NOW()';
        $stmt = $this->pdo->prepare('UPDATE languages SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $this->findById($id);
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
