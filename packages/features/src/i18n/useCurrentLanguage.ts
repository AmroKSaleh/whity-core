'use client'

/**
 * useCurrentLanguage hook — access and switch the current language.
 *
 * Usage:
 *   const { currentLanguage, availableLanguages, setLanguage } = useCurrentLanguage()
 *
 *   // Render language switcher
 *   {availableLanguages.map(lang => (
 *     <button
 *       key={lang.code}
 *       onClick={() => setLanguage(lang.code)}
 *       disabled={currentLanguage === lang.code}
 *     >
 *       {lang.name}
 *     </button>
 *   ))}
 *
 * Bilingual support:
 *   Multiple languages are cached simultaneously in localStorage, so bilingual users
 *   can switch instantly without refetching from the API.
 */

import { useCallback, useContext } from 'react'

import type { Language } from './types'
import { LanguageContext } from './LanguageProvider'

export interface UseCurrentLanguageReturn {
  currentLanguage: string | null
  availableLanguages: Language[]
  isLoading: boolean
  error: Error | null
  setLanguage: (code: string) => Promise<void>
}

/**
 * Hook to access and switch the current language.
 *
 * @returns Object with current language info and setLanguage function
 *
 * @throws If LanguageProvider is not in the component tree
 *
 * Example:
 *   const { currentLanguage, availableLanguages, setLanguage } = useCurrentLanguage()
 *
 *   return (
 *     <select value={currentLanguage || ''} onChange={(e) => setLanguage(e.target.value)}>
 *       {availableLanguages.map(lang => (
 *         <option key={lang.code} value={lang.code}>{lang.name}</option>
 *       ))}
 *     </select>
 *   )
 */
export function useCurrentLanguage(): UseCurrentLanguageReturn {
  const context = useContext(LanguageContext)

  if (!context) {
    throw new Error(
      'useCurrentLanguage must be used within a <LanguageProvider>. ' +
      'Make sure to wrap your app root with <LanguageProvider>.'
    )
  }

  // Memoize setLanguage to keep the hook's contract stable
  const setLanguage = useCallback(
    (code: string) => context.setLanguage(code),
    [context]
  )

  return {
    currentLanguage: context.currentLanguage,
    availableLanguages: context.availableLanguages,
    isLoading: context.isLoading,
    error: context.error,
    setLanguage,
  }
}
