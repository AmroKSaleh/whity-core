'use client'

/**
 * Whether this instance offers a CHOICE of language — the client side of the
 * operator's `i18n.enabled` feature flag (served on GET /api/v1/languages,
 * resolved by LanguageProvider).
 *
 * Read it wherever the interface would otherwise offer a language affordance —
 * a switcher, a "translated" badge, a per-language tab — so that a deployment
 * not ready to ship a second language shows none of them at all rather than
 * controls that appear to do nothing.
 *
 * It does NOT gate translation: `useTranslation` keeps returning real text with
 * the flag off (the default language's), so no screen needs to branch on this
 * to stay readable.
 *
 * Both hooks are deliberately NON-THROWING without a provider, like
 * useLanguageDirection: they are read by chrome that also renders in Storybook
 * and in isolated component tests.
 */

import { useContext } from 'react'

import { LanguageContext } from './LanguageProvider'

/**
 * What is known about this instance's language offering.
 *
 * 'unknown' covers both "the catalogue has not answered yet" and "no
 * LanguageProvider is mounted". It is a distinct state on purpose — see
 * {@link useI18nAvailability}.
 */
export type I18nAvailability = 'unknown' | 'enabled' | 'disabled'

/**
 * The three-valued answer, for the rarer caller that must tell "off" apart from
 * "not known yet".
 *
 * Use this for anything that ASSERTS the feature is off — the notice on the
 * admin Languages and Translations screens, say. Rendering that assertion
 * against `unknown` would state something false for a couple of hundred
 * milliseconds on every single load, which is a small lie an admin screen
 * should not tell. For hiding a CONTROL, prefer {@link useI18nEnabled}, where
 * collapsing `unknown` into "hide it" is exactly right.
 */
export function useI18nAvailability(): I18nAvailability {
  const enabled = useContext(LanguageContext)?.i18nEnabled ?? null
  if (enabled === null) {
    return 'unknown'
  }
  return enabled ? 'enabled' : 'disabled'
}

/**
 * @returns `true` only once the instance has confirmed i18n is on. `false`
 *          while the catalogue is still loading, when the flag is off, and when
 *          no LanguageProvider is mounted — an affordance stays hidden until
 *          something says it belongs there, never the other way round.
 */
export function useI18nEnabled(): boolean {
  return useContext(LanguageContext)?.i18nEnabled === true
}
