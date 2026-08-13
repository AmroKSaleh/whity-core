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

/**
 * The public language catalogue, plus whether this instance offers a CHOICE of
 * language at all.
 *
 * `i18nEnabled` is the operator's `i18n.enabled` feature flag, served on the
 * public languages payload because it must be known before a session exists —
 * the sign-in screen mounts the provider too. It is read from that explicit
 * field rather than inferred from how many languages came back: a single-language
 * install with the feature ON is a different thing from the feature being OFF,
 * and only the second one hides the switcher.
 */
export interface LanguageCatalogue {
  languages: Language[]
  i18nEnabled: boolean
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
  /**
   * Whether this instance offers a choice of language (`i18n.enabled`).
   *
   * FALSE means every user reads the default language left-to-right whatever
   * their profile stores, and NO language affordance is rendered anywhere.
   * Translation still works — `t()` returns the default language's text — so
   * this is not a switch that breaks translated screens; it is a switch that
   * removes the CHOICE.
   *
   * `false` until the catalogue has answered, deliberately: an instance with
   * the feature OFF must never paint a switcher and then take it away, which
   * is precisely the confusion the flag exists to remove. An instance with it
   * ON reveals the switcher when the catalogue lands, the same moment the
   * switcher would have had anything to show.
   */
  i18nEnabled: boolean
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
