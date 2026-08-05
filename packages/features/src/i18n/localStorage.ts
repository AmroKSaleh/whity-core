/**
 * LocalStorage utilities for i18n translation caching.
 *
 * Cache keys are formatted as: i18n_translations_{language_code}_{domain}
 * Values are JSON objects: { key: translation, ... }
 *
 * Each cache entry has a timestamp for potential TTL validation (24 hours default).
 */

export const CACHE_NAMESPACE = 'i18n_translations'
export const CACHE_TTL_MS = 24 * 60 * 60 * 1000 // 24 hours

interface CacheEntry {
  version: 1
  timestamp: number
  data: Record<string, string>
}

/**
 * Get cache key for a language and domain.
 *
 * @param languageCode Language code (e.g., 'en', 'ar')
 * @param domain Translation domain (e.g., 'common', 'email')
 * @returns Cache key string
 */
export function getCacheKey(languageCode: string, domain: string): string {
  return `${CACHE_NAMESPACE}_${languageCode}_${domain}`
}

/**
 * Store translations in localStorage.
 *
 * Stores the translations with a timestamp for TTL validation.
 * If localStorage is not available (private browsing, etc.), silently fails.
 *
 * @param languageCode Language code
 * @param domain Translation domain
 * @param translations Translation map { key: translation }
 */
export function setCachedTranslations(
  languageCode: string,
  domain: string,
  translations: Record<string, string>
): void {
  try {
    if (typeof window === 'undefined' || !window.localStorage) {
      return
    }

    const key = getCacheKey(languageCode, domain)
    const entry: CacheEntry = {
      version: 1,
      timestamp: Date.now(),
      data: translations,
    }

    window.localStorage.setItem(key, JSON.stringify(entry))
  } catch (e) {
    // Silently fail on quota exceeded or other storage errors
    // (private browsing, quota exceeded, etc.)
    console.warn('Failed to cache translations:', e)
  }
}

/**
 * Retrieve translations from localStorage.
 *
 * Returns null if:
 * - Cache entry doesn't exist
 * - Cache is expired (> 24 hours)
 * - Cache entry is malformed
 * - localStorage is not available
 *
 * @param languageCode Language code
 * @param domain Translation domain
 * @returns Translation map or null if not cached
 */
export function getCachedTranslations(
  languageCode: string,
  domain: string
): Record<string, string> | null {
  try {
    if (typeof window === 'undefined' || !window.localStorage) {
      return null
    }

    const key = getCacheKey(languageCode, domain)
    const cached = window.localStorage.getItem(key)

    if (!cached) {
      return null
    }

    const entry: CacheEntry = JSON.parse(cached)

    // Validate cache entry structure
    if (!entry.version || !entry.timestamp || !entry.data) {
      return null
    }

    // Check TTL (24 hours)
    const age = Date.now() - entry.timestamp
    if (age > CACHE_TTL_MS) {
      // Optionally delete expired cache (best effort)
      try {
        window.localStorage.removeItem(key)
      } catch {
        // Ignore cleanup errors
      }
      return null
    }

    return entry.data
  } catch (e) {
    // Silently fail on parse errors or other issues
    console.warn('Failed to retrieve cached translations:', e)
    return null
  }
}

/**
 * Clear all cached translations for a specific language.
 *
 * Useful when switching languages to ensure fresh data.
 * If localStorage is not available, silently fails.
 *
 * @param languageCode Language code to clear cache for
 */
export function clearLanguageCache(languageCode: string): void {
  try {
    if (typeof window === 'undefined' || !window.localStorage) {
      return
    }

    // Iterate through localStorage and remove all entries for this language
    const keysToDelete: string[] = []
    for (let i = 0; i < window.localStorage.length; i++) {
      const key = window.localStorage.key(i)
      if (key && key.startsWith(`${CACHE_NAMESPACE}_${languageCode}_`)) {
        keysToDelete.push(key)
      }
    }

    keysToDelete.forEach((key) => {
      window.localStorage.removeItem(key)
    })
  } catch (e) {
    // Silently fail
    console.warn('Failed to clear language cache:', e)
  }
}

/**
 * Clear all i18n caches.
 *
 * Use this when language data is invalidated globally.
 */
export function clearAllTranslationCaches(): void {
  try {
    if (typeof window === 'undefined' || !window.localStorage) {
      return
    }

    const keysToDelete: string[] = []
    for (let i = 0; i < window.localStorage.length; i++) {
      const key = window.localStorage.key(i)
      if (key && key.startsWith(CACHE_NAMESPACE)) {
        keysToDelete.push(key)
      }
    }

    keysToDelete.forEach((key) => {
      window.localStorage.removeItem(key)
    })
  } catch (e) {
    // Silently fail
    console.warn('Failed to clear all translation caches:', e)
  }
}
