/**
 * i18n — internationalization hooks and context for language management and translation.
 *
 * Exported components:
 * - LanguageProvider: Context provider that wraps your app
 * - useTranslation: Hook to translate strings in a domain
 * - useCurrentLanguage: Hook to get/set the current language
 * - useLanguageDirection: Hook for the resolved language's writing direction
 * - useI18nEnabled: Hook for whether this instance offers a language CHOICE
 *
 * Architecture:
 * - LanguageProvider initializes on mount, fetches available languages and user preference
 * - Language resolution: profile preference → locally remembered code → defaultLanguage
 * - THE WHOLE SURFACE IS FLAGGABLE: with the operator's `i18n.enabled` off
 *   (served on GET /api/v1/languages), every user resolves defaultLanguage in
 *   'ltr', `useI18nEnabled()` is false and the switcher renders nothing. Stored
 *   preferences are left untouched, so re-enabling restores them exactly.
 *   `useTranslation` is unaffected — it returns the default language's text.
 * - DIRECTION IS A PROPERTY OF THE LANGUAGE: each language record carries
 *   'ltr'/'rtl' and the app sets <html dir> from it. There is no separate
 *   direction toggle, and no code anywhere branches on a language code.
 * - Domains load ON DEMAND: useTranslation(domain) registers the domain and the
 *   provider fetches that bundle once per language. No central domain list.
 * - Domain naming: core domains are bare ('auth', 'common'); a plugin's are
 *   namespaced with its source slug ('acme:catalog') — see
 *   src/Core/i18n/TranslationDomain.php for the rule.
 * - Translations are cached in localStorage per language/domain (24-hour TTL)
 * - API endpoints: GET /api/v1/languages, GET/PATCH /api/v1/settings/language, GET /api/v1/translations/{lang}/{domain}
 * - Fallback chain: translation → supplied fallback → key
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
export { useTranslation, interpolate, type TranslateFn } from './useTranslation'
export { useCurrentLanguage, type UseCurrentLanguageReturn } from './useCurrentLanguage'
export { useLanguageDirection } from './useLanguageDirection'
export { useI18nEnabled, useI18nAvailability, type I18nAvailability } from './useI18nEnabled'
export { LanguageSwitcher, type LanguageSwitcherProps } from './LanguageSwitcher'
export type {
  Direction,
  Language,
  LanguageCatalogue,
  LanguageSettings,
  LanguageContextValue,
  TranslationMap,
} from './types'

// Re-export storage utilities for advanced use cases (testing, cache invalidation)
export {
  getCachedTranslations,
  setCachedTranslations,
  clearLanguageCache,
  clearAllTranslationCaches,
  getRememberedLanguage,
  setRememberedLanguage,
  LANGUAGE_PREFERENCE_KEY,
} from './localStorage'
