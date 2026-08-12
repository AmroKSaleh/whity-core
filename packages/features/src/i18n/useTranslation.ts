'use client'

/**
 * useTranslation hook — access translations in React components.
 *
 * Usage:
 *   const t = useTranslation('auth')
 *   const text = t('login.submit', 'Sign in')          // 'Sign in' or 'تسجيل الدخول'
 *   const hi   = t('login.welcome', 'Hi {name}', { name })
 *
 * Fallback chain:
 *   1. Translation for the current language
 *   2. The fallback the caller supplied (the English source string)
 *   3. The key itself
 *
 * ALWAYS PASS THE ENGLISH STRING AS THE FALLBACK. It is what renders before the
 * bundle arrives, what renders if a key was never seeded, and what a reviewer
 * reads in the diff — a converted screen must still be legible with the
 * translations layer removed entirely.
 *
 * Asking for a domain is what LOADS it: the hook registers the domain with the
 * provider, which fetches that bundle once per language. There is no central
 * list of domains to keep in sync — converting a screen is a local change to
 * that screen plus its seeded rows.
 *
 * DELIBERATELY NON-THROWING when no <LanguageProvider> is mounted, unlike
 * useCurrentLanguage. A translated component must stay renderable in isolation
 * — a unit test, a Storybook story — without every one of them wiring up a
 * provider (and paying for the two network fetches it makes on mount, which is
 * how ordered fetch mocks desync). With no provider it returns the fallback,
 * which is the same thing it returns before the bundle loads. Switching the
 * language genuinely needs the provider, so THAT hook still throws.
 */

import { useCallback, useContext, useEffect } from 'react'

import { LanguageContext } from './LanguageProvider'

/**
 * Substitute `{name}` placeholders in a translated string.
 *
 * Placeholders — not string concatenation — are how a sentence survives
 * translation: word order differs between languages, so the surrounding text
 * must stay one translatable unit with holes in it. An unknown placeholder is
 * left verbatim, so a missing variable is visible rather than silently blank.
 */
export function interpolate(template: string, vars?: Record<string, string | number>): string {
  if (!vars) {
    return template
  }
  return template.replace(/\{(\w+)\}/g, (match, name: string) =>
    Object.prototype.hasOwnProperty.call(vars, name) ? String(vars[name]) : match
  )
}

/** The translation function a screen calls. */
export type TranslateFn = (
  key: string,
  fallback?: string,
  vars?: Record<string, string | number>
) => string

/**
 * Hook to translate strings in a specific domain.
 *
 * @param domain The translation domain — bare for core ('auth', 'common'),
 *               namespaced for a plugin ('acme:catalog').
 * @returns A translation function t(key, fallback?, vars?)
 */
export function useTranslation(domain: string): TranslateFn {
  const context = useContext(LanguageContext)
  const ensureDomain = context?.ensureDomain
  const getTranslation = context?.getTranslation

  // Declare the domain this component needs. Idempotent in the provider, so
  // twenty components sharing a domain still cause exactly one fetch.
  useEffect(() => {
    ensureDomain?.(domain)
  }, [ensureDomain, domain])

  return useCallback(
    (key: string, fallback?: string, vars?: Record<string, string | number>): string => {
      const resolved = getTranslation ? getTranslation(domain, key, fallback ?? key) : fallback ?? key
      return interpolate(resolved, vars)
    },
    [getTranslation, domain]
  )
}
