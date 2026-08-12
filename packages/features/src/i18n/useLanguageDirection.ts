'use client'

/**
 * useLanguageDirection — the writing direction of the currently resolved
 * language.
 *
 * Direction is a PROPERTY OF THE LANGUAGE (`languages.direction`), not a
 * setting of its own: picking Arabic picks right-to-left, and picking English
 * picks left-to-right. Adding Hebrew, Farsi or Urdu is a row in that table —
 * this hook never inspects a language code, so no new language needs a code
 * change here or in any consumer.
 *
 * Deliberately NON-THROWING, unlike useTranslation/useCurrentLanguage: it is
 * read by the app's DirectionProvider, which also renders in contexts with no
 * language provider above it (Storybook, isolated component tests). Those
 * render left-to-right rather than crashing.
 */

import { useContext } from 'react'

import type { Direction } from './types'
import { LanguageContext } from './LanguageProvider'

/**
 * @returns 'rtl' when the resolved language declares it, otherwise 'ltr' —
 *          including before a language resolves, and when no LanguageProvider
 *          is mounted.
 */
export function useLanguageDirection(): Direction {
  return useContext(LanguageContext)?.direction ?? 'ltr'
}
