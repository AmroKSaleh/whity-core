/**
 * i18n — internationalization hooks and context for language management and translation.
 *
 * Exported components:
 * - LanguageProvider: Context provider that wraps your app
 * - useTranslation: Hook to translate strings in a domain
 * - useCurrentLanguage: Hook to get/set the current language
 *
 * Architecture:
 * - LanguageProvider initializes on mount, fetches available languages and user preference
 * - Translations are cached in localStorage per language/domain (24-hour TTL)
 * - API endpoints: GET /api/v1/languages, GET/PATCH /api/v1/settings/language, GET /api/v1/translations/{lang}/{domain}
 * - Fallback chain: translation → English → key
 * - Bilingual support: multiple languages cached simultaneously in localStorage
 *
 * Usage:
 *   // 1. Wrap your app root
 *   <LanguageProvider defaultLanguage="en">
 *     <App />
 *   </LanguageProvider>
 *
 *   // 2. Use in components
 *   const t = useTranslation('common')
 *   const { currentLanguage, setLanguage } = useCurrentLanguage()
 *   const message = t('button.save')  // 'Save' or 'حفظ'
 */

export { LanguageProvider, type LanguageProviderProps } from './LanguageProvider'
export { useTranslation } from './useTranslation'
export { useCurrentLanguage, type UseCurrentLanguageReturn } from './useCurrentLanguage'
export { LanguageSwitcher, type LanguageSwitcherProps } from './LanguageSwitcher'
export type { Language, LanguageSettings, LanguageContextValue, TranslationMap } from './types'

// Re-export storage utilities for advanced use cases (testing, cache invalidation)
export { getCachedTranslations, setCachedTranslations, clearLanguageCache, clearAllTranslationCaches } from './localStorage'
