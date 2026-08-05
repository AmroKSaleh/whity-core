'use client'

/**
 * LanguageProvider context component.
 *
 * Wraps the application and manages:
 * - Current language selection
 * - Available languages
 * - Translation caching
 * - Language persistence (localStorage + API)
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

import { createContext, useCallback, useEffect, useMemo, useState, ReactNode } from 'react'

import type { Language, LanguageContextValue, CachedTranslations } from './types'
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
} from './localStorage'

export const LanguageContext = createContext<LanguageContextValue | undefined>(undefined)

export interface LanguageProviderProps {
  children: ReactNode
  defaultLanguage?: string
}

export function LanguageProvider({
  children,
  defaultLanguage = 'en',
}: LanguageProviderProps) {
  const [currentLanguage, setCurrentLanguage] = useState<string | null>(null)
  const [availableLanguages, setAvailableLanguages] = useState<Language[]>([])
  const [translations, setTranslations] = useState<CachedTranslations>({})
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState<Error | null>(null)

  // Initialize on mount: fetch languages and user's language preference
  useEffect(() => {
    const initialize = async () => {
      try {
        setIsLoading(true)
        setError(null)

        // Fetch available languages
        const languages = await fetchAvailableLanguages()
        setAvailableLanguages(languages)

        // Try to fetch user's language preference (requires auth)
        const settings = await fetchLanguageSettings()
        let languageCode = settings?.language_code || defaultLanguage

        // Validate that the language is in the available list
        if (languageCode && !languages.find((l) => l.code === languageCode)) {
          languageCode = defaultLanguage
        }

        setCurrentLanguage(languageCode)
        setIsLoading(false)
      } catch (err) {
        console.error('Failed to initialize language provider:', err)
        setError(err instanceof Error ? err : new Error('Failed to initialize languages'))
        setCurrentLanguage(defaultLanguage)
        setIsLoading(false)
      }
    }

    initialize()
  }, [defaultLanguage])

  // Fetch translations for current language when it changes
  useEffect(() => {
    if (!currentLanguage) {
      return
    }

    const loadTranslations = async () => {
      try {
        const domains = ['common', 'email', 'errors'] // Add more domains as needed

        const newTranslations: CachedTranslations = {}

        for (const domain of domains) {
          // Try to get from cache first
          const cached = getCachedTranslations(currentLanguage, domain)
          if (cached) {
            newTranslations[domain] = cached
            continue
          }

          // Fetch from API
          const fetched = await fetchTranslations(currentLanguage, domain)
          if (Object.keys(fetched).length > 0) {
            newTranslations[domain] = fetched
            // Cache the translations
            setCachedTranslations(currentLanguage, domain, fetched)
          }
        }

        setTranslations(newTranslations)
      } catch (err) {
        console.error('Failed to load translations:', err)
        setError(err instanceof Error ? err : new Error('Failed to load translations'))
      }
    }

    loadTranslations()
  }, [currentLanguage])

  // Handle language change: update both locally and on the server
  const setLanguage = useCallback(
    async (code: string) => {
      try {
        setError(null)

        // Validate language
        const lang = availableLanguages.find((l) => l.code === code)
        if (!lang) {
          throw new Error(`Invalid language code: ${code}`)
        }

        // Update on server (which updates the database)
        const updated = await updateLanguagePreference(code)
        if (updated !== code) {
          throw new Error('Failed to update language preference on server')
        }

        // Clear cache for old language and set new one
        if (currentLanguage) {
          clearLanguageCache(currentLanguage)
        }

        // Update local state (which triggers translation fetch)
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

  const value = useMemo<LanguageContextValue>(
    () => ({
      currentLanguage,
      availableLanguages,
      translations,
      isLoading,
      error,
      setLanguage,
      getTranslation,
    }),
    [currentLanguage, availableLanguages, translations, isLoading, error, setLanguage, getTranslation]
  )

  return <LanguageContext.Provider value={value}>{children}</LanguageContext.Provider>
}
