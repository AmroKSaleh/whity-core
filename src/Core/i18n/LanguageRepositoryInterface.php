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

    /**
     * Create a new language.
     *
     * @param string $code    The language code (e.g., 'en', 'ar'). Must be unique.
     * @param string $name    The display name (e.g., 'English').
     * @param bool   $enabled Whether the language is enabled. Defaults to true.
     * @return Language|null The created Language, or null when a language with
     *                       this code already exists (caller returns 409).
     */
    public function create(string $code, string $name, bool $enabled = true): ?Language;

    /**
     * Update a language's name and/or enabled status.
     *
     * Passing null for a parameter leaves that field unchanged.
     *
     * @param int         $id      The language ID.
     * @param string|null $name    The new display name, or null to leave unchanged.
     * @param bool|null   $enabled The new enabled status, or null to leave unchanged.
     * @return Language|null The updated Language, or null when no language matched.
     */
    public function update(int $id, ?string $name, ?bool $enabled): ?Language;
}
