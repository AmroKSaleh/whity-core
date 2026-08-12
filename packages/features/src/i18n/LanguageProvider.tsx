'use client'

/**
 * LanguageProvider context component.
 *
 * Wraps the application and manages:
 * - Current language selection
 * - Available languages, each carrying its own writing DIRECTION
 * - Translation caching, fetched per domain on demand
 * - Language persistence (profile via API, localStorage for signed-out visitors)
 *
 * DIRECTION IS DERIVED, NOT CHOSEN. `direction` is read off the resolved
 * language record (`languages.direction`), so switching to Arabic flips the
 * interface to right-to-left and switching back flips it to left-to-right. A
 * third right-to-left language needs a row, not a release — nothing here tests
 * a language code. See lib/direction-context.tsx in the app for the consumer
 * that puts it on <html dir>.
 *
 * DOMAINS ARE LAZY. There is no list of domains in this file. A screen calling
 * `useTranslation('auth')` registers 'auth' through `ensureDomain`, and the
 * provider fetches that bundle once per language. Extraction can therefore fan
 * out screen by screen without anyone editing this provider.
 *
 * Usage:
 *   <LanguageProvider>
 *     <App />
 *   </LanguageProvider>
 *
 * Then in components:
 *   const { currentLanguage, setLanguage } = useCurrentLanguage()
 *   const t = useTranslation('common')
 */

import { createContext, useCallback, useEffect, useMemo, useRef, useState, ReactNode } from 'react'

import type { Language, LanguageContextValue, CachedTranslations, Direction } from './types'
import {
  fetchAvailableLanguages,
  fetchLanguageSettings,
  updateLanguagePreference,
  fetchTranslations,
} from './api'
import {
  getCachedTranslations,
  setCachedTranslations,
  clearLanguageCache,
  getRememberedLanguage,
  setRememberedLanguage,
} from './localStorage'

export const LanguageContext = createContext<LanguageContextValue | undefined>(undefined)

export interface LanguageProviderProps {
  children: ReactNode
  defaultLanguage?: string
  /**
   * An opaque handle for WHO is signed in (a profile id, or null when nobody
   * is). Changing it re-resolves the language from the new identity's profile.
   *
   * Without this the provider would resolve once on mount and never again:
   * signing in is a CLIENT-SIDE navigation, so a user whose profile says
   * Arabic would keep the anonymous English interface until they happened to
   * reload — which makes "the preference follows you across devices" true only
   * on the second page load. The provider deliberately takes a handle rather
   * than reading an auth context, so non-Next consumers (Tauri, Flutter shells)
   * can supply their own notion of identity.
   */
  identityKey?: string | number | null
}

/** The direction assumed before any language has resolved. */
const FALLBACK_DIRECTION: Direction = 'ltr'

export function LanguageProvider({
  children,
  defaultLanguage = 'en',
  identityKey = null,
}: LanguageProviderProps) {
  const [currentLanguage, setCurrentLanguage] = useState<string | null>(null)
  const [availableLanguages, setAvailableLanguages] = useState<Language[]>([])
  const [translations, setTranslations] = useState<CachedTranslations>({})
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState<Error | null>(null)
  // Domains some mounted component has asked for. Sorted+joined into the
  // effect's dependency so re-running is driven by the SET's contents, not by
  // the identity of a new array on every render.
  const [requestedDomains, setRequestedDomains] = useState<string[]>([])

  // Resolve the language on mount, and again whenever the signed-in identity
  // changes (sign-in, sign-out, account switch) — see `identityKey`.
  useEffect(() => {
    const initialize = async () => {
      try {
        setIsLoading(true)
        setError(null)

        // Fetch available languages
        const languages = await fetchAvailableLanguages()
        setAvailableLanguages(languages)

        // Resolution order: the signed-in user's PROFILE preference, then the
        // code remembered locally for a signed-out visitor (so the public
        // screens are not stuck in English), then the app default.
        const settings = await fetchLanguageSettings()
        let languageCode = settings?.language_code || getRememberedLanguage() || defaultLanguage

        // Validate that the language is in the available list — a code that has
        // since been disabled or deleted must not resurrect itself from storage.
        if (languageCode && !languages.find((l) => l.code === languageCode)) {
          languageCode = defaultLanguage
        }

        setCurrentLanguage(languageCode)
        setIsLoading(false)
      } catch (err) {
        // Degraded, not broken: the app renders in defaultLanguage with keys
        // as fallbacks. warn, not error — see fetchTranslations for why.
        console.warn('Language provider fell back to the default language:', err)
        setError(err instanceof Error ? err : new Error('Failed to initialize languages'))
        setCurrentLanguage(defaultLanguage)
        setIsLoading(false)
      }
    }

    initialize()
  }, [defaultLanguage, identityKey])

  // Remember the resolved language for the next signed-out visit, and reflect
  // it onto <html lang> for screen readers, hyphenation and font selection.
  // (Direction is applied by DirectionProvider, which reads `direction` below.)
  useEffect(() => {
    if (!currentLanguage) {
      return
    }
    setRememberedLanguage(currentLanguage)
    if (typeof document !== 'undefined') {
      document.documentElement.lang = currentLanguage
    }
  }, [currentLanguage])

  // Register a domain a component needs. Idempotent: calling it for an
  // already-known domain returns the same state array, so React bails out.
  const ensureDomain = useCallback((domain: string) => {
    if (!domain) {
      return
    }
    setRequestedDomains((prev) => (prev.includes(domain) ? prev : [...prev, domain]))
  }, [])

  // Which (language, domain) pairs have already been fetched this session, so a
  // re-render or a newly-registered domain does not refetch the others.
  const loadedRef = useRef<Set<string>>(new Set())
  const domainKey = [...requestedDomains].sort().join(',')

  // Fetch the bundles for every requested domain in the current language.
  useEffect(() => {
    if (!currentLanguage || requestedDomains.length === 0) {
      return
    }

    let cancelled = false

    const loadTranslations = async () => {
      try {
        const fresh: CachedTranslations = {}

        for (const domain of requestedDomains) {
          const pair = `${currentLanguage}:${domain}`
          if (loadedRef.current.has(pair)) {
            continue
          }
          loadedRef.current.add(pair)

          // localStorage first (24h TTL), network second.
          const cached = getCachedTranslations(currentLanguage, domain)
          if (cached) {
            fresh[domain] = cached
            continue
          }

          const fetched = await fetchTranslations(currentLanguage, domain)
          if (Object.keys(fetched).length > 0) {
            fresh[domain] = fetched
            setCachedTranslations(currentLanguage, domain, fetched)
          }
        }

        if (!cancelled && Object.keys(fresh).length > 0) {
          setTranslations((prev) => ({ ...prev, ...fresh }))
        }
      } catch (err) {
        console.warn('Translations unavailable; falling back to keys:', err)
        setError(err instanceof Error ? err : new Error('Failed to load translations'))
      }
    }

    loadTranslations()

    return () => {
      cancelled = true
    }
    // `domainKey` stands in for requestedDomains' CONTENTS (see above).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [currentLanguage, domainKey])

  // Handle language change: update both locally and on the server.
  const setLanguage = useCallback(
    async (code: string) => {
      try {
        setError(null)

        // Validate language
        const lang = availableLanguages.find((l) => l.code === code)
        if (!lang) {
          throw new Error(`Invalid language code: ${code}`)
        }

        // Persist to the profile — that is what makes the choice, and so the
        // direction, follow the user across devices. A SIGNED-OUT caller (the
        // sign-in screen mounts this provider too) has no profile: the switch
        // still applies, remembered locally only. A genuine server failure
        // still throws, so a 500 never masquerades as a saved preference.
        const outcome = await updateLanguagePreference(code)
        if (outcome.status === 'failed') {
          throw new Error('Failed to update language preference on server')
        }
        if (outcome.status === 'saved' && outcome.languageCode !== code) {
          throw new Error('Failed to update language preference on server')
        }

        // Drop the old language's bundles so a stale cache cannot outlive it,
        // and forget which pairs were loaded for it.
        if (currentLanguage) {
          clearLanguageCache(currentLanguage)
          for (const pair of [...loadedRef.current]) {
            if (pair.startsWith(`${currentLanguage}:`)) {
              loadedRef.current.delete(pair)
            }
          }
        }
        setTranslations({})

        // Update local state (which triggers the translation fetch, and the
        // direction change with it).
        setCurrentLanguage(code)
      } catch (err) {
        console.error('Failed to change language:', err)
        setError(err instanceof Error ? err : new Error('Failed to change language'))
        throw err
      }
    },
    [availableLanguages, currentLanguage]
  )

  // Helper to get a translation
  const getTranslation = useCallback(
    (domain: string, key: string, fallback?: string): string => {
      if (!currentLanguage) {
        return fallback || key
      }

      const domainTranslations = translations[domain]
      if (!domainTranslations) {
        return fallback || key
      }

      return domainTranslations[key] || fallback || key
    },
    [currentLanguage, translations]
  )

  // Direction comes off the RECORD, not off the code. An unknown or
  // not-yet-resolved language reads left-to-right rather than guessing.
  const direction: Direction =
    availableLanguages.find((l) => l.code === currentLanguage)?.direction ?? FALLBACK_DIRECTION

  const value = useMemo<LanguageContextValue>(
    () => ({
      currentLanguage,
      availableLanguages,
      direction,
      translations,
      isLoading,
      error,
      setLanguage,
      getTranslation,
      ensureDomain,
    }),
    [
      currentLanguage,
      availableLanguages,
      direction,
      translations,
      isLoading,
      error,
      setLanguage,
      getTranslation,
      ensureDomain,
    ]
  )

  return <LanguageContext.Provider value={value}>{children}</LanguageContext.Provider>
}
