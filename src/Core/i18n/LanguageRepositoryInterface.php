<?php

declare(strict_types=1);

namespace Whity\Core\i18n;

/**
 * Interface for Language repository.
 *
 * Provides read access to languages from the database. Languages are global
 * (not tenant-scoped) and all tenants can access the same set of language codes.
 */
interface LanguageRepositoryInterface
{
    /**
     * Get all languages, optionally filtered by enabled status.
     *
     * @param bool|null $enabled Filter by enabled status, or null for all.
     * @return Language[] List of Language objects, indexed by code.
     */
    public function findAll(?bool $enabled = null): array;

    /**
     * Get a language by its code.
     *
     * @param string $code The language code (e.g., 'en', 'ar').
     * @return Language|null The Language object, or null if not found.
     */
    public function findByCode(string $code): ?Language;

    /**
     * Get a language by its ID.
     *
     * @param int $id The language ID.
     * @return Language|null The Language object, or null if not found.
     */
    public function findById(int $id): ?Language;
}
