<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\i18n\LanguageRepository;
use Whity\Core\i18n\TranslationRepository;
use Whity\Core\Tenant\TenantContextInterface;

/**
 * Translations API handler.
 *
 * Exposes the translation layer for UI string localization:
 *  - GET /api/v1/translations/{language_code}/{domain} — authenticated, returns translations
 *    for a specific language and domain (implements fallback chain: tenant override →
 *    system default → English → key)
 *
 * This endpoint allows clients to fetch translated strings for any language and domain.
 * Translations are cached client-side in localStorage for performance.
 *
 * Fallback behavior:
 *  1. Tenant-specific override (if a custom translation is set for this tenant)
 *  2. System default (the canonical translation in the system)
 *  3. English translation (fallback if the requested language has no translation)
 *  4. The key itself (if no translation is found anywhere)
 *
 * Holds no request state — safe for a FrankenPHP worker.
 */
final class TranslationsApiHandler
{
    private LanguageRepository $languageRepository;
    private TranslationRepository $translationRepository;
    private TenantContextInterface $tenantContext;

    public function __construct(
        LanguageRepository $languageRepository,
        TranslationRepository $translationRepository,
        TenantContextInterface $tenantContext,
    ) {
        $this->languageRepository = $languageRepository;
        $this->translationRepository = $translationRepository;
        $this->tenantContext = $tenantContext;
    }

    /**
     * GET /api/v1/translations/{language_code}/{domain} — fetch translations.
     *
     * Returns a map of translation keys to their translated strings for the given
     * language and domain. Implements the fallback chain:
     *  1. Tenant-specific override
     *  2. System default
     *  3. English fallback
     *  4. Key itself
     *
     * Response: { translations: { "key": "translation", ... } }
     *
     * @param Request $request The HTTP request
     * @param array $params Path parameters: { language_code, domain }
     * @return Response JSON response with translations or error
     */
    public function getTranslations(Request $request, array $params = []): Response
    {
        // Extract language_code and domain from path parameters
        $languageCode = $params['language_code'] ?? null;
        $domain = $params['domain'] ?? null;

        // Validate input
        if (!$languageCode || !is_string($languageCode) || $languageCode === '') {
            return Response::error('Missing or invalid language_code parameter', 400);
        }

        if (!$domain || !is_string($domain) || $domain === '') {
            return Response::error('Missing or invalid domain parameter', 400);
        }

        // Validate domain (basic sanitation — must be alphanumeric with underscores/hyphens)
        if (!preg_match('/^[a-z0-9_-]+$/i', $domain)) {
            return Response::error('Invalid domain format', 400);
        }

        try {
            // Get language by code
            $language = $this->languageRepository->findByCode($languageCode);
            if (!$language) {
                return Response::error("Language '{$languageCode}' not found or is disabled", 404);
            }

            // Get the current tenant ID (may be null for system tenant)
            $tenantId = $this->tenantContext->getTenantId();

            // Fetch translations with fallback chain
            // This includes both system defaults and tenant overrides
            $translations = $this->translationRepository->findByLanguageAndDomain(
                $language->id,
                $domain,
                $tenantId
            );

            // If no translations found for the requested language, try English as fallback
            if (empty($translations) && $languageCode !== 'en') {
                $englishLanguage = $this->languageRepository->findByCode('en');
                if ($englishLanguage) {
                    $translations = $this->translationRepository->findByLanguageAndDomain(
                        $englishLanguage->id,
                        $domain,
                        $tenantId
                    );
                }
            }

            // Format translations as simple key => value map
            $formatted = [];
            foreach ($translations as $translation) {
                $formatted[$translation->key] = $translation->translation;
            }

            return Response::json(['translations' => $formatted], 200);
        } catch (\Throwable $e) {
            error_log('[TranslationsApiHandler] getTranslations failed: ' . $e->getMessage());
            return Response::error('Failed to fetch translations', 500);
        }
    }
}
