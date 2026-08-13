'use client'

/**
 * LanguageSwitcher component — a simple UI for switching languages.
 *
 * Renders a button/dropdown that allows users to switch between available languages.
 * Automatically updates the app language and persists the preference to the server.
 *
 * RENDERS NOTHING when the operator has switched i18n off (`i18n.enabled`).
 * That check lives here, in the component itself, rather than only at each call
 * site: "removes the buttons to remove confusion" has to hold for EVERY place
 * the switcher is mounted, including ones added later, and a call site that
 * forgets to ask would put the affordance back. Chrome that wraps the switcher
 * in its own frame (an icon, a bordered row) should ALSO call `useI18nEnabled`,
 * so an empty box does not survive the switcher inside it.
 *
 * Usage:
 *   <LanguageSwitcher />
 *
 * With custom styling:
 *   <LanguageSwitcher variant="dropdown" />
 */

import { useState, useCallback } from 'react'
import { useCurrentLanguage } from './useCurrentLanguage'
import { useI18nEnabled } from './useI18nEnabled'

export interface LanguageSwitcherProps {
  variant?: 'buttons' | 'dropdown'
  className?: string
}

/**
 * Simple language switcher component.
 *
 * Displays available languages and allows switching.
 * Handles loading and error states.
 *
 * @param variant Visual variant: 'buttons' (default) or 'dropdown'
 * @param className CSS class for styling
 */
export function LanguageSwitcher({
  variant = 'buttons',
  className = '',
}: LanguageSwitcherProps) {
  const { currentLanguage, availableLanguages, isLoading, error, setLanguage } =
    useCurrentLanguage()
  const i18nEnabled = useI18nEnabled()
  const [isSwitching, setIsSwitching] = useState(false)

  const handleLanguageChange = useCallback(
    async (code: string) => {
      if (code === currentLanguage || isSwitching) {
        return
      }

      try {
        setIsSwitching(true)
        await setLanguage(code)
      } catch (err) {
        console.error('Failed to change language:', err)
      } finally {
        setIsSwitching(false)
      }
    },
    [currentLanguage, isSwitching, setLanguage]
  )

  // i18n off: no control, and no placeholder either — not a disabled select, not
  // a "single language" note. There is nothing for a user to decide.
  if (!i18nEnabled) {
    return null
  }

  if (isLoading) {
    return <div className={`language-switcher loading ${className}`}>Loading...</div>
  }

  if (error) {
    return (
      <div className={`language-switcher error ${className}`}>
        Failed to load languages
      </div>
    )
  }

  if (variant === 'dropdown') {
    return (
      <select
        value={currentLanguage || 'en'}
        onChange={(e) => handleLanguageChange(e.target.value)}
        disabled={isSwitching}
        className={`language-switcher dropdown ${className}`}
      >
        {availableLanguages.map((lang) => (
          <option key={lang.code} value={lang.code}>
            {lang.name}
          </option>
        ))}
      </select>
    )
  }

  // Default: buttons variant
  return (
    <div className={`language-switcher buttons ${className}`}>
      {availableLanguages.map((lang) => (
        <button
          key={lang.code}
          onClick={() => handleLanguageChange(lang.code)}
          disabled={isSwitching || currentLanguage === lang.code}
          className={`language-button ${currentLanguage === lang.code ? 'active' : ''}`}
          title={lang.name}
        >
          {lang.code.toUpperCase()}
        </button>
      ))}
    </div>
  )
}
