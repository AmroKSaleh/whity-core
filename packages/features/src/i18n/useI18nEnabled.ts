'use client'

/**
 * useI18nEnabled — whether this instance offers a CHOICE of language.
 *
 * The client side of the operator's `i18n.enabled` feature flag (served on
 * GET /api/v1/languages, resolved by LanguageProvider). Read it wherever the
 * interface would otherwise offer a language affordance — a switcher, a
 * "translated" badge, a per-language tab — so that a deployment which is not
 * ready to ship a second language shows none of them at all rather than
 * controls that appear to do nothing.
 *
 * It does NOT gate translation: `useTranslation` keeps returning real text with
 * the flag off (the default language's), so no screen needs to branch on this
 * to stay readable. What disappears is the choice, not the machinery.
 *
 * Deliberately NON-THROWING without a provider, like useLanguageDirection: it
 * is read by chrome that also renders in Storybook and in isolated component
 * tests. With nothing above it the answer is `false` — no provider has said
 * this instance offers a language choice, so no affordance is drawn.
 */

import { useContext } from 'react'

import { LanguageContext } from './LanguageProvider'

/**
 * @returns `true` once the instance has confirmed i18n is on; `false` while the
 *          catalogue is still loading, when the flag is off, and when no
 *          LanguageProvider is mounted.
 */
export function useI18nEnabled(): boolean {
  return useContext(LanguageContext)?.i18nEnabled ?? false
}
