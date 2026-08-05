<?php

declare(strict_types=1);

namespace Whity\Core\i18n;

use Whity\Core\Tenant\TenantContextInterface;

/**
 * In-memory translation registry service.
 *
 * Loads all translations into memory at boot time and provides O(1) lookup access.
 * This service is request-scoped but holds a boot-time cache of translations that
 * is queried on every lookup — the cache is NOT per-request, it's per-boot.
 *
 * Structure of the in-memory cache:
 *   $translations[language_code][domain][key] = translation_string
 *
 * The fallback chain for translations is: tenant override → system default → key itself.
 * Tenant isolation is enforced: only translations for NULL tenant_id or the current
 * tenant_id are visible.
 */
final class LanguageRegistry
{
    /**
     * In-memory translation cache, keyed by language code, domain, and key.
     * Structure: [language_code][domain][key] = translation_string
     *
     * @var array<string, array<string, array<string, string>>>
     */
    private array $translations = [];

    /**
     * Languages cache, keyed by code.
     *
     * @var array<string, Language>
     */
    private array $languages = [];

    /**
     * Whether the registry has been booted (cache loaded).
     */
    private bool $booted = false;

    /**
     * The current language code for this request.
     */
    private string $currentLanguageCode = 'en';

    public function __construct(
        private readonly LanguageRepository $languageRepository,
        private readonly TranslationRepository $translationRepository,
        private readonly TenantContextInterface $tenantContext,
    ) {
    }

    /**
     * Boot the registry: load all translations into memory.
     *
     * This MUST be called once at application startup (before any translation
     * lookups are performed). It loads all languages and all system default
     * translations into the in-memory cache. Tenant overrides are loaded
     * separately when a translation is requested for a specific tenant.
     *
     * Boot is idempotent: calling it multiple times is safe and resets the cache.
     *
     * @return void
     */
    public function boot(): void
    {
        // Clear existing cache to allow re-boot.
        $this->translations = [];
        $this->languages = [];

        // Load all languages.
        $this->languages = $this->languageRepository->findAll(enabled: true);

        // Load all system default translations for all languages.
        foreach ($this->languages as $language) {
            $this->translations[$language->code] = [];

            // Load system defaults (tenant_id = NULL).
            $systemDefaults = $this->translationRepository->findAllSystemDefaults($language->id);
            foreach ($systemDefaults as $domain => $keys) {
                if (!isset($this->translations[$language->code][$domain])) {
                    $this->translations[$language->code][$domain] = [];
                }
                foreach ($keys as $key => $translation) {
                    $this->translations[$language->code][$domain][$key] = $translation->translation;
                }
            }
        }

        $this->booted = true;
    }

    /**
     * Get a translator function for a specific domain in the current language.
     *
     * Returns a callable that takes a key and returns the translated string.
     * The callable implements the fallback chain: tenant override → system default → key.
     *
     * @param string $domain The translation domain (e.g., 'common', 'email').
     * @return callable(string $key): string A translation function.
     */
    public function getTranslator(string $domain): callable
    {
        $languageCode = $this->currentLanguageCode;
        $tenantId = $this->tenantContext->getTenantId();

        return function (string $key) use ($languageCode, $domain, $tenantId): string {
            return $this->translate($languageCode, $domain, $key, $tenantId);
        };
    }

    /**
     * Translate a key in a specific language, domain, and tenant scope.
     *
     * Implements the fallback chain:
     *   1. Tenant-specific override (if tenantId is set and override exists)
     *   2. System default (tenant_id = NULL)
     *   3. The key itself (if no translation found)
     *
     * @param string $languageCode The language code (e.g., 'en', 'ar').
     * @param string $domain       The translation domain.
     * @param string $key          The translation key.
     * @param int|null $tenantId   The current tenant ID, or null for system tenant.
     * @return string The translated string, or the key if not found.
     */
    public function translate(string $languageCode, string $domain, string $key, ?int $tenantId = null): string
    {
        // Ensure registry is booted before any lookups.
        if (!$this->booted) {
            $this->boot();
        }

        // If language is not in cache, fall back to English.
        if (!isset($this->translations[$languageCode])) {
            $languageCode = 'en';
        }

        // If domain is not in cache, return key.
        if (!isset($this->translations[$languageCode][$domain])) {
            return $key;
        }

        // Check if a tenant override exists (if tenantId is set).
        if ($tenantId !== null) {
            $tenantOverride = $this->getTranslationForTenant(
                $languageCode,
                $domain,
                $key,
                $tenantId
            );
            if ($tenantOverride !== null) {
                return $tenantOverride;
            }
        }

        // Fall back to system default.
        return $this->translations[$languageCode][$domain][$key] ?? $key;
    }

    /**
     * Get a translation specifically for a tenant (if override exists).
     *
     * This method checks if there's a tenant-specific override for a key in the
     * database. If it doesn't exist in memory, it queries the database and caches
     * the result.
     *
     * @param string   $languageCode The language code.
     * @param string   $domain       The translation domain.
     * @param string   $key          The translation key.
     * @param int      $tenantId     The tenant ID.
     * @return string|null The tenant-specific translation, or null if not found.
     */
    private function getTranslationForTenant(
        string $languageCode,
        string $domain,
        string $key,
        int $tenantId
    ): ?string {
        // Check if we have a tenant cache for this language.
        $cacheKey = "tenant_{$tenantId}";
        if (!isset($this->translations[$languageCode][$cacheKey])) {
            // Load tenant overrides for this language and tenant.
            $language = $this->languages[$languageCode] ?? null;
            if ($language === null) {
                return null;
            }

            $this->translations[$languageCode][$cacheKey] = [];
            $tenantOverrides = $this->translationRepository->findAllTenantOverrides(
                $language->id,
                $tenantId
            );

            // Flatten tenant overrides into a domain/key structure.
            foreach ($tenantOverrides as $d => $keys) {
                if (!isset($this->translations[$languageCode][$d])) {
                    $this->translations[$languageCode][$d] = [];
                }
                foreach ($keys as $k => $translation) {
                    $this->translations[$languageCode][$d][$k] = $translation->translation;
                }
            }
        }

        // Check if the override exists in the domain.
        return $this->translations[$languageCode][$domain][$key] ?? null;
    }

    /**
     * Get the list of available languages for the current context.
     *
     * Returns all enabled languages. Optionally filters by tenant
     * (though currently languages are global and not tenant-scoped).
     *
     * @param int|null $tenantId Optional tenant ID filter (currently unused).
     * @return Language[] List of Language objects, indexed by code.
     */
    public function getLanguages(?int $tenantId = null): array
    {
        if (!$this->booted) {
            $this->boot();
        }

        return $this->languages;
    }

    /**
     * Get the current language code.
     *
     * Defaults to 'en'. Can be overridden via setCurrentLanguage().
     *
     * @return string The current language code.
     */
    public function getCurrentLanguageCode(): string
    {
        return $this->currentLanguageCode;
    }

    /**
     * Get the current Language object.
     *
     * @return Language|null The current Language, or null if not found.
     */
    public function getCurrentLanguage(): ?Language
    {
        return $this->languages[$this->currentLanguageCode] ?? null;
    }

    /**
     * Set the current language for this request.
     *
     * Must be called with a valid language code. Defaults to 'en'.
     *
     * @param string $code The language code (e.g., 'en', 'ar').
     * @return void
     */
    public function setCurrentLanguage(string $code): void
    {
        if (!$this->booted) {
            $this->boot();
        }

        if (isset($this->languages[$code])) {
            $this->currentLanguageCode = $code;
        }
    }

    /**
     * Invalidate the translation cache and reload from the database.
     *
     * Use this when translations change at runtime (e.g., during testing or
     * when an admin edits translations). This clears the in-memory cache and
     * re-boots the registry.
     *
     * @return void
     */
    public function invalidateCache(): void
    {
        $this->booted = false;
        $this->boot();
    }
}
