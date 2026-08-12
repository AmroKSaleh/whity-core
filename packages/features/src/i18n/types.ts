/**
 * i18n types and interfaces for language management and translation.
 */

/**
 * Interface writing direction.
 *
 * This is a property of the LANGUAGE, never a separate user setting: choosing
 * Arabic IS choosing right-to-left. The value is served on every language
 * record (`languages.direction`, migration 090), so adding Hebrew, Farsi or
 * Urdu is one row through the admin languages API — no client code branches on
 * a language code to decide direction.
 */
export type Direction = 'ltr' | 'rtl'

export interface Language {
  code: string
  name: string
  direction: Direction
}

export interface LanguageSettings {
  language_code: string | null
  available_languages: Language[]
}

export interface TranslationMap {
  [key: string]: string
}

export interface CachedTranslations {
  [domain: string]: TranslationMap
}

export interface LanguageContextValue {
  currentLanguage: string | null
  availableLanguages: Language[]
  /** The resolved language's direction; 'ltr' until a language resolves. */
  direction: Direction
  translations: CachedTranslations
  isLoading: boolean
  error: Error | null
  setLanguage: (code: string) => Promise<void>
  getTranslation: (domain: string, key: string, fallback?: string) => string
  /**
   * Declare that a domain's bundle is needed. Idempotent and cheap to call on
   * every render; the provider fetches each (language, domain) pair once.
   *
   * Screens do not call this directly — `useTranslation(domain)` does, so a
   * screen asking for its strings is all it takes to have them loaded. That is
   * why there is no hardcoded list of domains anywhere.
   */
  ensureDomain: (domain: string) => void
}
