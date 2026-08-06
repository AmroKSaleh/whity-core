/**
 * Tests for localStorage i18n caching utilities.
 */

import {
  getCacheKey,
  getCachedTranslations,
  setCachedTranslations,
  clearLanguageCache,
  clearAllTranslationCaches,
} from '../localStorage'

// Mock localStorage
const localStorageMock = (() => {
  let store: Record<string, string> = {}

  return {
    getItem: (key: string) => store[key] || null,
    setItem: (key: string, value: string) => {
      store[key] = value.toString()
    },
    removeItem: (key: string) => {
      delete store[key]
    },
    clear: () => {
      store = {}
    },
    key: (index: number) => {
      const keys = Object.keys(store)
      return keys[index] || null
    },
    get length() {
      return Object.keys(store).length
    },
  }
})()

Object.defineProperty(window, 'localStorage', {
  value: localStorageMock,
})

describe('i18n localStorage utilities', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  describe('getCacheKey', () => {
    it('should format cache key correctly', () => {
      const key = getCacheKey('ar', 'common')
      expect(key).toMatch(/i18n_translations/)
      expect(key).toContain('ar')
      expect(key).toContain('common')
    })

    it('should handle different languages and domains', () => {
      const key1 = getCacheKey('en', 'email')
      const key2 = getCacheKey('ar', 'errors')
      expect(key1).not.toBe(key2)
    })
  })

  describe('setCachedTranslations', () => {
    it('should store translations in localStorage', () => {
      const translations = { 'button.save': 'Save', 'button.cancel': 'Cancel' }
      setCachedTranslations('en', 'common', translations)

      const key = getCacheKey('en', 'common')
      const stored = localStorage.getItem(key)
      expect(stored).toBeTruthy()

      const parsed = JSON.parse(stored!)
      expect(parsed.version).toBe(1)
      expect(parsed.data).toEqual(translations)
      expect(parsed.timestamp).toBeTruthy()
    })

    it('should store translations with timestamp', () => {
      const translations = { 'key': 'value' }
      const beforeTime = Date.now()
      setCachedTranslations('en', 'common', translations)
      const afterTime = Date.now()

      const key = getCacheKey('en', 'common')
      const stored = JSON.parse(localStorage.getItem(key)!)
      expect(stored.timestamp).toBeGreaterThanOrEqual(beforeTime)
      expect(stored.timestamp).toBeLessThanOrEqual(afterTime)
    })

    it('should handle multiple domains', () => {
      const translations1 = { 'email.subject': 'Welcome' }
      const translations2 = { 'error.notfound': 'Not Found' }

      setCachedTranslations('en', 'email', translations1)
      setCachedTranslations('en', 'errors', translations2)

      const key1 = getCacheKey('en', 'email')
      const key2 = getCacheKey('en', 'errors')

      expect(localStorage.getItem(key1)).toContain('Welcome')
      expect(localStorage.getItem(key2)).toContain('Not Found')
    })

    it('should silently fail if localStorage is unavailable', () => {
      const originalLocalStorage = (global as any).localStorage
      delete (global as any).localStorage

      expect(() => {
        setCachedTranslations('en', 'common', { key: 'value' })
      }).not.toThrow()

      ;(global as any).localStorage = originalLocalStorage
    })
  })

  describe('getCachedTranslations', () => {
    it('should retrieve stored translations', () => {
      const translations = { 'button.save': 'Save', 'button.cancel': 'Cancel' }
      setCachedTranslations('en', 'common', translations)

      const retrieved = getCachedTranslations('en', 'common')
      expect(retrieved).toEqual(translations)
    })

    it('should return null for non-existent cache', () => {
      const retrieved = getCachedTranslations('en', 'nonexistent')
      expect(retrieved).toBeNull()
    })

    it('should validate cache entry structure', () => {
      const key = getCacheKey('en', 'common')
      localStorage.setItem(key, JSON.stringify({ invalid: 'structure' }))

      const retrieved = getCachedTranslations('en', 'common')
      expect(retrieved).toBeNull()
    })

    it('should return null if cache is expired', () => {
      const translations = { 'key': 'value' }
      setCachedTranslations('en', 'common', translations)

      // Manually set an old timestamp
      const key = getCacheKey('en', 'common')
      const entry = JSON.parse(localStorage.getItem(key)!)
      entry.timestamp = Date.now() - 25 * 60 * 60 * 1000 // 25 hours ago
      localStorage.setItem(key, JSON.stringify(entry))

      const retrieved = getCachedTranslations('en', 'common')
      expect(retrieved).toBeNull()
    })

    it('should handle malformed JSON gracefully', () => {
      const key = getCacheKey('en', 'common')
      localStorage.setItem(key, 'invalid json {')

      expect(() => {
        getCachedTranslations('en', 'common')
      }).not.toThrow()

      const retrieved = getCachedTranslations('en', 'common')
      expect(retrieved).toBeNull()
    })

    it('should silently fail if localStorage is unavailable', () => {
      const originalLocalStorage = (global as any).localStorage
      delete (global as any).localStorage

      expect(() => {
        getCachedTranslations('en', 'common')
      }).not.toThrow()

      const result = getCachedTranslations('en', 'common')
      expect(result).toBeNull()

      ;(global as any).localStorage = originalLocalStorage
    })
  })

  describe('clearLanguageCache', () => {
    it('should clear cache for a specific language', () => {
      setCachedTranslations('en', 'common', { key: 'value' })
      setCachedTranslations('en', 'email', { key: 'value' })
      setCachedTranslations('ar', 'common', { key: 'value' })

      clearLanguageCache('en')

      expect(getCachedTranslations('en', 'common')).toBeNull()
      expect(getCachedTranslations('en', 'email')).toBeNull()
      expect(getCachedTranslations('ar', 'common')).toEqual({ key: 'value' })
    })

    it('should not throw if language cache does not exist', () => {
      expect(() => {
        clearLanguageCache('nonexistent')
      }).not.toThrow()
    })

    it('should silently fail if localStorage is unavailable', () => {
      const originalLocalStorage = (global as any).localStorage
      delete (global as any).localStorage

      expect(() => {
        clearLanguageCache('en')
      }).not.toThrow()

      ;(global as any).localStorage = originalLocalStorage
    })
  })

  describe('clearAllTranslationCaches', () => {
    it('should clear all i18n caches', () => {
      setCachedTranslations('en', 'common', { key: 'value' })
      setCachedTranslations('en', 'email', { key: 'value' })
      setCachedTranslations('ar', 'common', { key: 'value' })

      clearAllTranslationCaches()

      expect(getCachedTranslations('en', 'common')).toBeNull()
      expect(getCachedTranslations('en', 'email')).toBeNull()
      expect(getCachedTranslations('ar', 'common')).toBeNull()
    })

    it('should not affect non-i18n localStorage entries', () => {
      localStorage.setItem('other_key', 'other_value')
      setCachedTranslations('en', 'common', { key: 'value' })

      clearAllTranslationCaches()

      expect(localStorage.getItem('other_key')).toBe('other_value')
    })

    it('should silently fail if localStorage is unavailable', () => {
      const originalLocalStorage = (global as any).localStorage
      delete (global as any).localStorage

      expect(() => {
        clearAllTranslationCaches()
      }).not.toThrow()

      ;(global as any).localStorage = originalLocalStorage
    })
  })
})
