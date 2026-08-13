/**
 * Tests for i18n React hooks.
 */

import { render, renderHook, screen, act, waitFor } from '@testing-library/react'
import { ReactNode } from 'react'
import {
  LanguageProvider,
  LanguageSwitcher,
  useTranslation,
  useCurrentLanguage,
  useI18nEnabled,
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

    mockApi.fetchLanguageCatalogue.mockResolvedValue({
      languages: [
        { code: 'en', name: 'English', direction: 'ltr' },
        { code: 'ar', name: 'العربية', direction: 'rtl' },
      ],
      i18nEnabled: true,
    })

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

    mockApi.fetchLanguageCatalogue.mockResolvedValue({
      languages: [
        { code: 'en', name: 'English', direction: 'ltr' },
        { code: 'ar', name: 'العربية', direction: 'rtl' },
      ],
      i18nEnabled: true,
    })

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

    mockApi.fetchLanguageCatalogue.mockResolvedValue({
      languages: [
        { code: 'en', name: 'English', direction: 'ltr' },
        { code: 'ar', name: 'العربية', direction: 'rtl' },
      ],
      i18nEnabled: true,
    })
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
    mockApi.fetchLanguageCatalogue.mockResolvedValue({
      languages: [
        { code: 'en', name: 'English', direction: 'ltr' },
        { code: 'ar', name: 'العربية', direction: 'rtl' },
        hebrew,
      ],
      i18nEnabled: true,
    })
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

    mockApi.fetchLanguageCatalogue.mockResolvedValue({
      languages: [
        { code: 'en', name: 'English', direction: 'ltr' },
        { code: 'ar', name: 'العربية', direction: 'rtl' },
      ],
      i18nEnabled: true,
    })
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

    mockApi.fetchLanguageCatalogue.mockResolvedValue({
      languages: [
        { code: 'en', name: 'English', direction: 'ltr' },
        { code: 'ar', name: 'العربية', direction: 'rtl' },
      ],
      i18nEnabled: true,
    })

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
    mockApi.fetchLanguageCatalogue.mockRejectedValue(new Error('Network error'))

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

/**
 * The i18n FEATURE FLAG (`i18n.enabled`).
 *
 * Off means: one language, left-to-right, no affordance — and, above all,
 * nothing destroyed. The last test in this block is the one that matters most:
 * a flag you cannot safely switch back is not a flag, it is a migration.
 */
describe('i18n feature flag', () => {
  const LANGUAGES = [
    { code: 'en', name: 'English', direction: 'ltr' as const },
    { code: 'ar', name: 'العربية', direction: 'rtl' as const },
  ]

  /**
   * Arrange an instance with the flag in a given state, and a profile whose
   * stored preference is Arabic — the case that proves the flag overrides a
   * real, saved choice rather than merely leaving an unset one alone.
   */
  function arrangeInstance(i18nEnabled: boolean) {
    mockApi.fetchLanguageCatalogue.mockResolvedValue({ languages: LANGUAGES, i18nEnabled })
    mockApi.fetchLanguageSettings.mockResolvedValue({
      language_code: 'ar',
      available_languages: LANGUAGES,
    })
  }

  beforeEach(() => {
    jest.clearAllMocks()
    localStorage.clear()
    // Seeded so a disabled instance renders REAL English text, not a raw key:
    // switching i18n off must not turn translated screens into key soup.
    mockApi.fetchTranslations.mockImplementation((language) =>
      Promise.resolve(
        language === 'ar' ? { 'login.submit': 'تسجيل الدخول' } : { 'login.submit': 'Sign in' }
      )
    )
    mockApi.updateLanguagePreference.mockImplementation((code) =>
      Promise.resolve({ status: 'saved' as const, languageCode: code })
    )
  })

  it('renders the default language left-to-right for a profile set to Arabic', async () => {
    arrangeInstance(false)

    const { result } = renderHook(
      () => ({ lang: useCurrentLanguage(), dir: useLanguageDirection() }),
      { wrapper }
    )

    await waitFor(() => expect(result.current.lang.currentLanguage).toBe('en'))
    expect(result.current.dir).toBe('ltr')
  })

  /**
   * The stored preference is not merely overridden — it is never even asked
   * for. A preference the instance will not honour is not a preference the
   * instance should be reading.
   */
  it('does not consult the profile preference at all while disabled', async () => {
    arrangeInstance(false)

    const { result } = renderHook(() => useCurrentLanguage(), { wrapper })

    await waitFor(() => expect(result.current.currentLanguage).toBe('en'))
    expect(mockApi.fetchLanguageSettings).not.toHaveBeenCalled()
  })

  it('reports i18n as unavailable, so callers can drop their language affordances', async () => {
    arrangeInstance(false)

    const { result } = renderHook(() => useI18nEnabled(), { wrapper })

    await waitFor(() => expect(result.current).toBe(false))
  })

  it('reports i18n as available when the instance offers it', async () => {
    arrangeInstance(true)

    const { result } = renderHook(() => useI18nEnabled(), { wrapper })

    await waitFor(() => expect(result.current).toBe(true))
  })

  /**
   * `t()` KEEPS WORKING. The flag removes the CHOICE of language, not the
   * translation machinery: a converted screen must render its real English
   * text, never the key.
   */
  it('keeps t() returning real text — never a raw key — while disabled', async () => {
    arrangeInstance(false)

    const { result } = renderHook(() => useTranslation('auth'), { wrapper })

    // Asserted WITHOUT a fallback, which is the only form that can tell the two
    // apart: it reads 'Sign in' only once the seeded English bundle is actually
    // in hand, and would read the raw key 'login.submit' if the flag had cut
    // translation loading off.
    await waitFor(() => expect(result.current('login.submit')).toBe('Sign in'))
    expect(mockApi.fetchTranslations).toHaveBeenCalledWith('en', 'auth')
    expect(result.current('login.submit', 'Sign in')).toBe('Sign in')
  })

  it('refuses a language change rather than pretending to save one', async () => {
    arrangeInstance(false)

    const { result } = renderHook(() => useCurrentLanguage(), { wrapper })
    await waitFor(() => expect(result.current.currentLanguage).toBe('en'))

    await expect(
      act(async () => {
        await result.current.setLanguage('ar')
      })
    ).rejects.toThrow(/disabled/)
    expect(mockApi.updateLanguagePreference).not.toHaveBeenCalled()
  })

  /**
   * The switcher self-suppresses, so a call site added later cannot
   * accidentally put the affordance back.
   */
  it('renders no language switcher at all', async () => {
    arrangeInstance(false)

    render(
      <LanguageProvider defaultLanguage="en">
        <LanguageSwitcher variant="dropdown" />
      </LanguageProvider>
    )

    await waitFor(() => expect(screen.queryByRole('combobox')).not.toBeInTheDocument())
    expect(screen.queryByText('Loading...')).not.toBeInTheDocument()
  })

  it('renders the switcher again once the instance offers a choice', async () => {
    arrangeInstance(true)

    render(
      <LanguageProvider defaultLanguage="en">
        <LanguageSwitcher variant="dropdown" />
      </LanguageProvider>
    )

    await waitFor(() => expect(screen.getByRole('combobox')).toBeInTheDocument())
  })

  /**
   * THE PROPERTY THE WHOLE FEATURE RESTS ON: disabling is not a data
   * migration. The same profile, unchanged on the server, comes back Arabic the
   * moment the flag is switched on — and while it was off, the code this
   * browser remembered was not overwritten with the default either.
   */
  it('restores the stored Arabic preference intact when re-enabled', async () => {
    localStorage.setItem('i18n_language', 'ar')
    arrangeInstance(false)

    const disabled = render(
      <LanguageProvider defaultLanguage="en">
        <DirectionProbe />
      </LanguageProvider>
    )
    await waitFor(() => expect(screen.getByTestId('dir')).toHaveTextContent('ltr'))
    // Nothing wrote over what the browser remembered while the feature was off.
    expect(localStorage.getItem('i18n_language')).toBe('ar')
    disabled.unmount()

    // The operator switches the flag back on. Nothing else changed: the same
    // profile payload, the same catalogue.
    arrangeInstance(true)

    render(
      <LanguageProvider defaultLanguage="en">
        <DirectionProbe />
      </LanguageProvider>
    )

    await waitFor(() => expect(screen.getByTestId('dir')).toHaveTextContent('rtl'))
  })
})
