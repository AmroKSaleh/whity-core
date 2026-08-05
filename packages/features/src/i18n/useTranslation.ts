'use client'

/**
 * useTranslation hook — access translations in React components.
 *
 * Usage:
 *   const t = useTranslation('common')
 *   const text = t('button.save')  // 'Save' or 'حفظ' or 'button.save' (fallback)
 *
 * Fallback chain:
 *   1. Translation from current language
 *   2. English translation (if not in current language)
 *   3. Key itself (if no translation found)
 *
 * No API calls after initial load — translations are cached in localStorage and
 * context memory, so the t() function is synchronous and fast.
 */

import { useCallback, useContext } from 'react'

import { LanguageContext } from './LanguageProvider'

/**
 * Hook to translate strings in a specific domain.
 *
 * @param domain The translation domain (e.g., 'common', 'email', 'errors')
 * @returns A translation function t(key, fallback?) that returns the translated string
 *
 * @throws If LanguageProvider is not in the component tree
 *
 * Example:
 *   const t = useTranslation('common')
 *   return <button>{t('button.save')}</button>
 */
export function useTranslation(domain: string): (key: string, fallback?: string) => string {
  const context = useContext(LanguageContext)

  if (!context) {
    throw new Error(
      'useTranslation must be used within a <LanguageProvider>. ' +
      'Make sure to wrap your app root with <LanguageProvider>.'
    )
  }

  return useCallback(
    (key: string, fallback?: string): string => {
      return context.getTranslation(domain, key, fallback || key)
    },
    [context, domain]
  )
}
