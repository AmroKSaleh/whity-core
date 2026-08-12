/**
 * API utilities for i18n language and translation endpoints.
 *
 * Provides typed wrappers around the Whity API for:
 * - GET /api/v1/languages — fetch available languages
 * - GET /api/v1/settings/language — fetch user's current language preference
 * - PATCH /api/v1/settings/language — update user's language preference
 * - GET /api/v1/translations/{language_code}/{domain} — fetch translations
 */

import type { Direction, Language, LanguageSettings, TranslationMap } from './types'

/**
 * Coerce a server-supplied language record into the client shape.
 *
 * `direction` is read defensively: a payload from a host that predates the
 * `languages.direction` column reads left-to-right rather than producing an
 * `undefined` that would reach the `dir` attribute.
 */
function toLanguage(raw: unknown): Language | null {
  if (typeof raw !== 'object' || raw === null) {
    return null
  }
  const record = raw as Record<string, unknown>
  if (typeof record.code !== 'string' || typeof record.name !== 'string') {
    return null
  }
  const direction: Direction = record.direction === 'rtl' ? 'rtl' : 'ltr'
  return { code: record.code, name: record.name, direction }
}

function toLanguages(raw: unknown): Language[] {
  return Array.isArray(raw) ? raw.map(toLanguage).filter((l): l is Language => l !== null) : []
}

/**
 * The outcome of persisting a language preference.
 *
 * Deliberately three-valued rather than `string | null`: a SIGNED-OUT caller
 * (the sign-in screen mounts this provider too) has no profile to write to, and
 * that is a normal outcome the switcher must keep working through — whereas a
 * real server failure must still surface. Collapsing the two would make a 500
 * look like a successful change.
 */
export type LanguagePreferenceUpdate =
  | { status: 'saved'; languageCode: string | null }
  | { status: 'anonymous' }
  | { status: 'failed' }

/**
 * Fetch the list of available languages from the API.
 *
 * GET /api/v1/languages (public endpoint, no auth required)
 *
 * @returns Resolved list of available languages, or empty array on error
 */
export async function fetchAvailableLanguages(): Promise<Language[]> {
  try {
    const response = await fetch('/api/v1/languages', {
      method: 'GET',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    })

    if (!response.ok) {
      console.warn(`Language list unavailable (${response.status}); using the default language`)
      return []
    }

    const data = await response.json()
    return toLanguages(data.languages)
  } catch (error) {
    console.warn('Language list unavailable; using the default language', error)
    return []
  }
}

/**
 * Fetch the user's current language preference and available languages.
 *
 * GET /api/v1/settings/language (authenticated)
 *
 * Returns the user's language_code preference (or null if not set) and
 * the list of available languages.
 *
 * @returns Language settings object or null on error
 */
export async function fetchLanguageSettings(): Promise<LanguageSettings | null> {
  try {
    const response = await fetch('/api/v1/settings/language', {
      method: 'GET',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    })

    if (!response.ok) {
      // Signed-out callers are the common case, not an error: the provider
      // mounts on /login too. EnforceTenantIsolation answers an unauthenticated
      // caller with 403 (no tenant to resolve), so 403 counts as "not signed
      // in" here alongside 401 — logging it would fire on every login-page
      // load and, in dev, raise Next's error overlay over the form.
      if (response.status === 401 || response.status === 403) {
        return null
      }
      console.warn(`Language preference unavailable (${response.status}); using the default language`)
      return null
    }

    const data = await response.json()
    return {
      language_code: typeof data.language_code === 'string' ? data.language_code : null,
      available_languages: toLanguages(data.available_languages),
    }
  } catch (error) {
    console.warn('Language preference unavailable; using the default language', error)
    return null
  }
}

/**
 * Update the user's language preference.
 *
 * PATCH /api/v1/settings/language (authenticated)
 *
 * A 401/403 is reported as `anonymous`, not as a failure: the provider mounts
 * on the public screens too, where there is no profile to write to and the
 * choice is kept locally instead. EnforceTenantIsolation answers an
 * unauthenticated caller with 403 (no tenant to resolve), hence both codes.
 *
 * @param languageCode New language code (e.g., 'ar', 'en') or null to reset to default
 * @returns The outcome — see {@link LanguagePreferenceUpdate}
 */
export async function updateLanguagePreference(
  languageCode: string | null
): Promise<LanguagePreferenceUpdate> {
  try {
    const response = await fetch('/api/v1/settings/language', {
      method: 'PATCH',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({ language_code: languageCode }),
    })

    if (response.status === 401 || response.status === 403) {
      return { status: 'anonymous' }
    }

    if (!response.ok) {
      console.error('Failed to update language preference:', response.status, response.statusText)
      return { status: 'failed' }
    }

    const data = await response.json()
    return {
      status: 'saved',
      languageCode: typeof data.language_code === 'string' ? data.language_code : null,
    }
  } catch (error) {
    console.error('Error updating language preference:', error)
    return { status: 'failed' }
  }
}

/**
 * Fetch translations for a specific language and domain.
 *
 * GET /api/v1/translations/{language_code}/{domain}
 *
 * Returns a map of translation keys to translated strings.
 * Implements fallback chain: tenant override → system default → English → key
 *
 * @param languageCode Language code (e.g., 'en', 'ar')
 * @param domain Translation domain (e.g., 'common', 'email')
 * @returns Translation map { key: translation, ... } or empty object on error
 */
export async function fetchTranslations(
  languageCode: string,
  domain: string
): Promise<TranslationMap> {
  try {
    const response = await fetch(`/api/v1/translations/${languageCode}/${domain}`, {
      method: 'GET',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    })

    // A missing bundle is a normal degraded state, not a fault: useTranslation
    // falls back to the key, so the UI stays readable. Deliberately NOT
    // console.error — Next's dev overlay promotes console.error to a portal
    // that covers the page and swallows pointer events, which turns a missing
    // translation domain into unclickable chrome (and red e2e runs).
    if (!response.ok) {
      console.warn(
        `Translations unavailable for ${languageCode}/${domain} (${response.status}); falling back to keys`
      )
      return {}
    }

    const data = await response.json()
    return data.translations || {}
  } catch (error) {
    console.warn(`Translations unavailable for ${languageCode}/${domain}; falling back to keys`, error)
    return {}
  }
}
