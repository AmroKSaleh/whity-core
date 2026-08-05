/**
 * i18n types and interfaces for language management and translation.
 */

export interface Language {
  code: string
  name: string
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
  translations: CachedTranslations
  isLoading: boolean
  error: Error | null
  setLanguage: (code: string) => Promise<void>
  getTranslation: (domain: string, key: string, fallback?: string) => string
}
