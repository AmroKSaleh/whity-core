/**
 * Tests for i18n React hooks.
 */

import { renderHook, act, waitFor } from '@testing-library/react'
import { ReactNode } from 'react'
import { LanguageProvider, useTranslation, useCurrentLanguage } from '../index'
import * as api from '../api'

// Mock the API module
jest.mock('../api')
const mockApi = api as jest.Mocked<typeof api>

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

const wrapper = ({ children }: { children: ReactNode }) => (
  <LanguageProvider defaultLanguage="en">{children}</LanguageProvider>
)

describe('useTranslation hook', () => {
  beforeEach(() => {
    jest.clearAllMocks()
    localStorage.clear()

    mockApi.fetchAvailableLanguages.mockResolvedValue([
      { code: 'en', name: 'English' },
      { code: 'ar', name: 'العربية' },
    ])

    mockApi.fetchLanguageSettings.mockResolvedValue({
      language_code: 'en',
      available_languages: [
        { code: 'en', name: 'English' },
        { code: 'ar', name: 'العربية' },
      ],
    })

    mockApi.fetchTranslations.mockResolvedValue({
      'button.save': 'Save',
      'button.cancel': 'Cancel',
    })
  })

  it('should throw if used outside LanguageProvider', () => {
    // @testing-library/react's renderHook has no `result.error` (that was the
    // old react-hooks testing library) — a throwing hook propagates out of
    // render, so assert on the call itself.
    expect(() => renderHook(() => useTranslation('common'))).toThrow(/LanguageProvider/)
  })

  it('should return a translation function', async () => {
    const { result } = renderHook(() => useTranslation('common'), { wrapper })

    await waitFor(() => {
      const t = result.current
      expect(typeof t).toBe('function')
    })
  })

  it('should translate keys correctly', async () => {
    const { result } = renderHook(() => useTranslation('common'), { wrapper })

    await waitFor(() => {
      const t = result.current
      const translated = t('button.save')
      expect(translated).toBe('Save')
    })
  })

  it('should return key as fallback if translation not found', async () => {
    const { result } = renderHook(() => useTranslation('common'), { wrapper })

    await waitFor(() => {
      const t = result.current
      const translated = t('nonexistent.key')
      expect(translated).toBe('nonexistent.key')
    })
  })

  it('should use provided fallback', async () => {
    const { result } = renderHook(() => useTranslation('common'), { wrapper })

    await waitFor(() => {
      const t = result.current
      const translated = t('nonexistent.key', 'Custom Fallback')
      expect(translated).toBe('Custom Fallback')
    })
  })

  it('should handle multiple domains', async () => {
    mockApi.fetchTranslations.mockImplementation((lang, domain) => {
      if (domain === 'email') {
        return Promise.resolve({ 'email.welcome': 'Welcome!' })
      }
      return Promise.resolve({ 'button.save': 'Save' })
    })

    const { result: commonResult } = renderHook(() => useTranslation('common'), { wrapper })
    const { result: emailResult } = renderHook(() => useTranslation('email'), { wrapper })

    await waitFor(() => {
      expect(commonResult.current('button.save')).toBe('Save')
      expect(emailResult.current('email.welcome')).toBe('Welcome!')
    })
  })
})

describe('useCurrentLanguage hook', () => {
  beforeEach(() => {
    jest.clearAllMocks()
    localStorage.clear()

    mockApi.fetchAvailableLanguages.mockResolvedValue([
      { code: 'en', name: 'English' },
      { code: 'ar', name: 'العربية' },
    ])

    mockApi.fetchLanguageSettings.mockResolvedValue({
      language_code: 'en',
      available_languages: [
        { code: 'en', name: 'English' },
        { code: 'ar', name: 'العربية' },
      ],
    })

    mockApi.fetchTranslations.mockResolvedValue({})
    mockApi.updateLanguagePreference.mockResolvedValue('ar')
  })

  it('should throw if used outside LanguageProvider', () => {
    expect(() => renderHook(() => useCurrentLanguage())).toThrow(/LanguageProvider/)
  })

  it('should return current language info', async () => {
    const { result } = renderHook(() => useCurrentLanguage(), { wrapper })

    await waitFor(() => {
      expect(result.current.currentLanguage).toBe('en')
      expect(result.current.availableLanguages).toHaveLength(2)
      expect(result.current.isLoading).toBe(false)
    })
  })

  it('should switch languages', async () => {
    const { result } = renderHook(() => useCurrentLanguage(), { wrapper })

    await waitFor(() => {
      expect(result.current.currentLanguage).toBe('en')
    })

    act(() => {
      result.current.setLanguage('ar')
    })

    await waitFor(() => {
      expect(result.current.currentLanguage).toBe('ar')
      expect(mockApi.updateLanguagePreference).toHaveBeenCalledWith('ar')
    })
  })

  it('should reject invalid language codes', async () => {
    const { result } = renderHook(() => useCurrentLanguage(), { wrapper })

    await waitFor(() => {
      expect(result.current.currentLanguage).toBe('en')
    })

    await expect(
      act(async () => {
        await result.current.setLanguage('invalid')
      })
    ).rejects.toThrow('Invalid language code')
  })

  it('should handle language switch errors', async () => {
    mockApi.updateLanguagePreference.mockResolvedValue(null)

    const { result } = renderHook(() => useCurrentLanguage(), { wrapper })

    await waitFor(() => {
      expect(result.current.currentLanguage).toBe('en')
    })

    await expect(
      act(async () => {
        await result.current.setLanguage('ar')
      })
    ).rejects.toThrow('Failed to update language preference')
  })
})

describe('LanguageProvider', () => {
  beforeEach(() => {
    jest.clearAllMocks()
    localStorage.clear()

    mockApi.fetchAvailableLanguages.mockResolvedValue([
      { code: 'en', name: 'English' },
      { code: 'ar', name: 'العربية' },
    ])

    mockApi.fetchLanguageSettings.mockResolvedValue({
      language_code: 'ar',
      available_languages: [
        { code: 'en', name: 'English' },
        { code: 'ar', name: 'العربية' },
      ],
    })

    mockApi.fetchTranslations.mockResolvedValue({
      'hello': 'السلام عليكم',
    })
  })

  it('should initialize with user language preference', async () => {
    const { result } = renderHook(() => useCurrentLanguage(), { wrapper })

    await waitFor(() => {
      expect(result.current.currentLanguage).toBe('ar')
    })
  })

  it('should fallback to default language if preference returns invalid code', async () => {
    mockApi.fetchLanguageSettings.mockResolvedValue({
      language_code: 'invalid',
      available_languages: [
        { code: 'en', name: 'English' },
        { code: 'ar', name: 'العربية' },
      ],
    })

    const { result } = renderHook(() => useCurrentLanguage(), { wrapper })

    await waitFor(() => {
      expect(result.current.currentLanguage).toBe('en')
    })
  })

  it('should handle API errors gracefully', async () => {
    mockApi.fetchAvailableLanguages.mockRejectedValue(new Error('Network error'))

    const { result } = renderHook(() => useCurrentLanguage(), { wrapper })

    await waitFor(() => {
      expect(result.current.error).toBeTruthy()
      expect(result.current.currentLanguage).toBe('en')
    })
  })

  it('should load translations on mount', async () => {
    const { result: langResult } = renderHook(() => useCurrentLanguage(), { wrapper })
    const { result: transResult } = renderHook(() => useTranslation('common'), { wrapper })

    await waitFor(() => {
      expect(mockApi.fetchTranslations).toHaveBeenCalled()
    })
  })

  it('should fall back to the custom default when the user has no preference', async () => {
    // No stored preference — this is the only case defaultLanguage decides.
    // (A stored preference wins; that is covered above.)
    mockApi.fetchLanguageSettings.mockResolvedValue({
      language_code: null,
      available_languages: [
        { code: 'en', name: 'English' },
        { code: 'ar', name: 'العربية' },
      ],
    })

    const customWrapper = ({ children }: { children: ReactNode }) => (
      <LanguageProvider defaultLanguage="ar">{children}</LanguageProvider>
    )

    const { result } = renderHook(() => useCurrentLanguage(), {
      wrapper: customWrapper,
    })

    // Nothing is resolved until the round-trip settles: the provider reports
    // isLoading rather than guessing a language it may have to swap out.
    expect(result.current.currentLanguage).toBeNull()

    await waitFor(() => {
      expect(result.current.currentLanguage).toBe('ar')
    })
  })
})
