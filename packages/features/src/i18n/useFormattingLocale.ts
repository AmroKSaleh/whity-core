'use client'

/**
 * useFormattingLocale — the language code to hand to `Intl` and to the
 * `toLocale*String` family.
 *
 * THE BUG THIS EXISTS TO CLOSE
 * ----------------------------
 * Dates and numbers were being formatted with no locale at all, on every
 * document screen. `date.toLocaleString()` with no argument follows the
 * BROWSER's locale, and the comment justifying that claimed "the app's own
 * locale negotiation happens above this". There was no such negotiation:
 * `useCurrentLanguage` was referenced only inside this package, by the language
 * switcher. So a person who had chosen Arabic, on a machine set up in English,
 * read `8/24/2026, 5:47:00 PM` in the middle of an Arabic right-to-left
 * sentence — Latin digits, Gregorian month order, and a bidi reordering that
 * makes the whole line hard to scan.
 *
 * A browser's Accept-Language and a person's choice inside this product are
 * different statements, and when they disagree the in-product choice is the one
 * that was made deliberately. This hook is that choice.
 *
 * NON-THROWING, and for the same reason {@see useLanguageDirection} is: it gets
 * called from components that also render with no LanguageProvider above them
 * (Storybook, isolated unit tests). Returning `undefined` there is not a
 * degraded mode — it is precisely the old behaviour, so a component that has
 * not been wired up yet formats exactly as it did before.
 *
 * It never inspects a language code, so adding Hebrew or Urdu is a row in the
 * `languages` table and no change here.
 */

import { useContext } from 'react'

import { LanguageContext } from './LanguageProvider'

/**
 * @returns the resolved language code (e.g. 'ar', 'en-GB'), or `undefined`
 *          before a language resolves and when no LanguageProvider is mounted —
 *          which is the value `Intl` and `toLocale*String` already treat as
 *          "use the runtime default".
 */
export function useFormattingLocale(): string | undefined {
  return useContext(LanguageContext)?.currentLanguage ?? undefined
}
