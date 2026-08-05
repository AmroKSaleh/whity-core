/**
 * API utilities for i18n language and translation endpoints.
 *
 * Provides typed wrappers around the Whity API for:
 * - GET /api/v1/languages — fetch available languages
 * - GET /api/v1/settings/language — fetch user's current language preference
 * - PATCH /api/v1/settings/language — update user's language preference
 * - GET /api/v1/translations/{language_code}/{domain} — fetch translations
 */

import type { Language, LanguageSettings, TranslationMap } from './types'

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
      console.error('Failed to fetch languages:', response.status, response.statusText)
      return []
    }

    const data = await response.json()
    return data.languages || []
  } catch (error) {
    console.error('Error fetching languages:', error)
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
      if (response.status === 401) {
        // Not authenticated — return null and let the app handle login
        return null
      }
      console.error('Failed to fetch language settings:', response.status, response.statusText)
      return null
    }

    return response.json()
  } catch (error) {
    console.error('Error fetching language settings:', error)
    return null
  }
}

/**
 * Update the user's language preference.
 *
 * PATCH /api/v1/settings/language (authenticated)
 *
 * @param languageCode New language code (e.g., 'ar', 'en') or null to reset to default
 * @returns Updated language code on success, or null on error
 */
export async function updateLanguagePreference(languageCode: string | null): Promise<string | null> {
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

    if (!response.ok) {
      console.error('Failed to update language preference:', response.status, response.statusText)
      return null
    }

    const data = await response.json()
    return data.language_code || null
  } catch (error) {
    console.error('Error updating language preference:', error)
    return null
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

    if (!response.ok) {
      console.error(
        `Failed to fetch translations for ${languageCode}/${domain}:`,
        response.status,
        response.statusText
      )
      return {}
    }

    const data = await response.json()
    return data.translations || {}
  } catch (error) {
    console.error(`Error fetching translations for ${languageCode}/${domain}:`, error)
    return {}
  }
}
