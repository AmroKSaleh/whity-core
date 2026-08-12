/**
 * Tests for i18n React hooks.
 */

import { render, renderHook, screen, act, waitFor } from '@testing-library/react'
import { ReactNode } from 'react'
import {
  LanguageProvider,
  useTranslation,
  useCurrentLanguage,
  useLanguageDirection,
} from '../index'
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

/** Renders the resolved direction so it can be asserted from a real tree. */
function DirectionProbe() {
  return <span data-testid="dir">{useLanguageDirection()}</span>
}

describe('useTranslation hook', () => {
  beforeEach(() => {
    jest.clearAllMocks()
    localStorage.clear()

    mockApi.fetchAvailableLanguages.mockResolvedValue([
      { code: 'en', name: 'English', direction: 'ltr' },
      { code: 'ar', name: 'العربية', direction: 'rtl' },
    ])

    mockApi.fetchLanguageSettings.mockResolvedValue({
      language_code: 'en',
      available_languages: [
        { code: 'en', name: 'English', direction: 'ltr' },
        { code: 'ar', name: 'العربية', direction: 'rtl' },
      ],
    })

    mockApi.fetchTranslations.mockResolvedValue({
      'button.save': 'Save',
      'button.cancel': 'Cancel',
    })
  })

  // Deliberately NON-throwing, unlike useCurrentLanguage: a translated screen
  // must stay renderable in a unit test or a Storybook story that never mounts
  // the provider (and never pays for its two mount-time fetches, which is how
  // ordered fetch mocks desync). With no provider it yields the fallback — the
  // same thing it yields before the bundle has loaded.
  it('falls back to the supplied English text outside a LanguageProvider', () => {
    const { result } = renderHook(() => useTranslation('auth'))

    expect(result.current('login.submit', 'Sign in')).toBe('Sign in')
    expect(result.current('login.submit')).toBe('login.submit')
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
      { code: 'en', name: 'English', direction: 'ltr' },
      { code: 'ar', name: 'العربية', direction: 'rtl' },
    ])

    mockApi.fetchLanguageSettings.mockResolvedValue({
      language_code: 'en',
      available_languages: [
        { code: 'en', name: 'English', direction: 'ltr' },
        { code: 'ar', name: 'العربية', direction: 'rtl' },
      ],
    })

    mockApi.fetchTranslations.mockResolvedValue({})
    mockApi.updateLanguagePreference.mockResolvedValue({ status: 'saved', languageCode: 'ar' })
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
    mockApi.updateLanguagePreference.mockResolvedValue({ status: 'failed' })

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

  // A signed-out visitor (the sign-in screen mounts this provider too) has no
  // profile to write to. That is a normal outcome, not a failure: the switch
  // applies locally so the public screens can be read in the chosen language.
  it('applies the language locally when there is no session to save it to', async () => {
    mockApi.updateLanguagePreference.mockResolvedValue({ status: 'anonymous' })

    const { result } = renderHook(() => useCurrentLanguage(), { wrapper })

    await waitFor(() => {
      expect(result.current.currentLanguage).toBe('en')
    })

    await act(async () => {
      await result.current.setLanguage('ar')
    })

    expect(result.current.currentLanguage).toBe('ar')
  })
})

/**
 * Direction is a property of the LANGUAGE — never a separate toggle, and never
 * a branch on a language code.
 */
describe('language direction', () => {
  beforeEach(() => {
    jest.clearAllMocks()
    localStorage.clear()

    mockApi.fetchAvailableLanguages.mockResolvedValue([
      { code: 'en', name: 'English', direction: 'ltr' },
      { code: 'ar', name: 'العربية', direction: 'rtl' },
    ])
    mockApi.fetchLanguageSettings.mockResolvedValue({
      language_code: 'en',
      available_languages: [
        { code: 'en', name: 'English', direction: 'ltr' },
        { code: 'ar', name: 'العربية', direction: 'rtl' },
      ],
    })
    mockApi.fetchTranslations.mockResolvedValue({})
    mockApi.updateLanguagePreference.mockImplementation((code) =>
      Promise.resolve({ status: 'saved' as const, languageCode: code })
    )
  })

  it('switches direction with the language, in both directions', async () => {
    const { result } = renderHook(
      () => ({ lang: useCurrentLanguage(), dir: useLanguageDirection() }),
      { wrapper }
    )

    // Wait for the language to RESOLVE, not merely for a direction to exist:
    // 'ltr' is also what reads back before anything has loaded.
    await waitFor(() => expect(result.current.lang.currentLanguage).toBe('en'))
    expect(result.current.dir).toBe('ltr')

    await act(async () => {
      await result.current.lang.setLanguage('ar')
    })
    await waitFor(() => expect(result.current.dir).toBe('rtl'))

    await act(async () => {
      await result.current.lang.setLanguage('en')
    })
    await waitFor(() => expect(result.current.dir).toBe('ltr'))
  })

  /**
   * THE POINT OF THE COLUMN. Hebrew is not mentioned anywhere in the client —
   * the record says 'rtl' and the interface mirrors. If this ever needs a code
   * change, the direction has been hardcoded somewhere it shouldn't be.
   */
  it('mirrors for a third right-to-left language with no code change', async () => {
    const hebrew = { code: 'he', name: 'עברית', direction: 'rtl' as const }
    mockApi.fetchAvailableLanguages.mockResolvedValue([
      { code: 'en', name: 'English', direction: 'ltr' },
      { code: 'ar', name: 'العربية', direction: 'rtl' },
      hebrew,
    ])
    mockApi.fetchLanguageSettings.mockResolvedValue({
      language_code: 'he',
      available_languages: [hebrew],
    })

    const { result } = renderHook(() => useLanguageDirection(), { wrapper })

    await waitFor(() => expect(result.current).toBe('rtl'))
  })

  it('reads left-to-right with no provider mounted', () => {
    const { result } = renderHook(() => useLanguageDirection())

    expect(result.current).toBe('ltr')
  })

  /**
   * Signing in is a CLIENT-SIDE navigation — the provider never remounts. It
   * must therefore re-resolve when the identity changes, or a user whose
   * profile says Arabic lands on an English left-to-right app until they
   * happen to reload.
   */
  it('re-resolves the language when the signed-in identity changes', async () => {
    // Anonymous: no profile preference to read.
    mockApi.fetchLanguageSettings.mockResolvedValue(null)

    // renderHook's `wrapper` only ever receives `children`, so the identity has
    // to be a real prop on a rendered tree rather than a hook argument.
    const tree = (identity: number | null) => (
      <LanguageProvider defaultLanguage="en" identityKey={identity}>
        <DirectionProbe />
      </LanguageProvider>
    )

    const { rerender } = render(tree(null))
    await waitFor(() => expect(screen.getByTestId('dir')).toHaveTextContent('ltr'))

    // Sign in as a profile whose preference is Arabic.
    mockApi.fetchLanguageSettings.mockResolvedValue({
      language_code: 'ar',
      available_languages: [{ code: 'ar', name: 'العربية', direction: 'rtl' }],
    })
    rerender(tree(42))

    await waitFor(() => expect(screen.getByTestId('dir')).toHaveTextContent('rtl'))
  })
})

/**
 * Domains load because a screen asked for one — there is no list to maintain.
 */
describe('lazy domain loading', () => {
  beforeEach(() => {
    jest.clearAllMocks()
    localStorage.clear()

    mockApi.fetchAvailableLanguages.mockResolvedValue([
      { code: 'en', name: 'English', direction: 'ltr' },
      { code: 'ar', name: 'العربية', direction: 'rtl' },
    ])
    mockApi.fetchLanguageSettings.mockResolvedValue({
      language_code: 'en',
      available_languages: [{ code: 'en', name: 'English', direction: 'ltr' }],
    })
    mockApi.fetchTranslations.mockResolvedValue({ 'login.submit': 'Sign in' })
    mockApi.updateLanguagePreference.mockResolvedValue({ status: 'saved', languageCode: 'ar' })
  })

  it('fetches only the domains a mounted screen asked for', async () => {
    renderHook(() => useTranslation('auth'), { wrapper })

    await waitFor(() => expect(mockApi.fetchTranslations).toHaveBeenCalledWith('en', 'auth'))

    const domains = mockApi.fetchTranslations.mock.calls.map(([, domain]) => domain)
    expect(new Set(domains)).toEqual(new Set(['auth']))
  })

  it('interpolates placeholders into a translated sentence', async () => {
    mockApi.fetchTranslations.mockResolvedValue({ 'login.welcome': 'أهلاً بك في {site}' })

    const { result } = renderHook(() => useTranslation('auth'), { wrapper })

    await waitFor(() => {
      expect(result.current('login.welcome', 'Welcome to {site}', { site: 'Acme' })).toBe(
        'أهلاً بك في Acme'
      )
    })
  })
})

describe('LanguageProvider', () => {
  beforeEach(() => {
    jest.clearAllMocks()
    localStorage.clear()

    mockApi.fetchAvailableLanguages.mockResolvedValue([
      { code: 'en', name: 'English', direction: 'ltr' },
      { code: 'ar', name: 'العربية', direction: 'rtl' },
    ])

    mockApi.fetchLanguageSettings.mockResolvedValue({
      language_code: 'ar',
      available_languages: [
        { code: 'en', name: 'English', direction: 'ltr' },
        { code: 'ar', name: 'العربية', direction: 'rtl' },
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
        { code: 'en', name: 'English', direction: 'ltr' },
        { code: 'ar', name: 'العربية', direction: 'rtl' },
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
        { code: 'en', name: 'English', direction: 'ltr' },
        { code: 'ar', name: 'العربية', direction: 'rtl' },
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
