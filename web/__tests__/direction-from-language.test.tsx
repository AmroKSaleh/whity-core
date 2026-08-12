import React from 'react';
import { render, screen, waitFor, act } from '@testing-library/react';
import { LanguageProvider, useCurrentLanguage } from '@amroksaleh/features/i18n';
import { DirectionProvider, useDirection } from '@/lib/direction-context';

/**
 * Interface direction is a property of the CHOSEN LANGUAGE.
 *
 * These tests pin the whole seam end to end at the app level: a language record
 * carries 'ltr'/'rtl', the LanguageProvider resolves it, and DirectionProvider
 * is the single place that writes `dir` onto <html>. There is no separate
 * direction preference to disagree with the language.
 */

const LANGUAGES = [
  { code: 'en', name: 'English', direction: 'ltr' },
  { code: 'ar', name: 'العربية', direction: 'rtl' },
];

/** The key the retired manual toggle used to write. Never read again. */
const RETIRED_DIRECTION_KEY = 'whity.dir';

function Harness() {
  const { dir } = useDirection();
  const { currentLanguage, setLanguage } = useCurrentLanguage();
  return (
    <div>
      <span data-testid="dir">{dir}</span>
      <span data-testid="lang">{currentLanguage ?? ''}</span>
      <button onClick={() => void setLanguage('ar')}>to-ar</button>
      <button onClick={() => void setLanguage('en')}>to-en</button>
    </div>
  );
}

function renderApp() {
  return render(
    <LanguageProvider>
      <DirectionProvider>
        <Harness />
      </DirectionProvider>
    </LanguageProvider>
  );
}

/**
 * Route the provider's calls by URL rather than by call order — the provider
 * makes several on mount and an order-sensitive queue would be brittle.
 */
function mockApi({ profileLanguage }: { profileLanguage: string | null }) {
  (global.fetch as jest.Mock).mockImplementation((url: string, init?: RequestInit) => {
    if (url === '/api/v1/languages') {
      return Promise.resolve({ ok: true, status: 200, json: async () => ({ languages: LANGUAGES }) });
    }
    if (url === '/api/v1/settings/language' && (!init || init.method === 'GET')) {
      return Promise.resolve({
        ok: true,
        status: 200,
        json: async () => ({ language_code: profileLanguage, available_languages: LANGUAGES }),
      });
    }
    if (url === '/api/v1/settings/language' && init?.method === 'PATCH') {
      const body = JSON.parse(String(init.body)) as { language_code: string };
      // Stand in for the profile row the backend actually writes.
      profileLanguage = body.language_code;
      return Promise.resolve({
        ok: true,
        status: 200,
        json: async () => ({ language_code: body.language_code }),
      });
    }
    if (url.startsWith('/api/v1/translations/')) {
      return Promise.resolve({ ok: true, status: 200, json: async () => ({ translations: {} }) });
    }
    return Promise.resolve({ ok: false, status: 404, json: async () => ({}) });
  });
}

describe('interface direction follows the chosen language', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.clear();
    document.documentElement.dir = '';
    global.fetch = jest.fn();
  });

  it('renders right-to-left when the resolved language declares it', async () => {
    mockApi({ profileLanguage: 'ar' });

    renderApp();

    await waitFor(() => expect(screen.getByTestId('dir')).toHaveTextContent('rtl'));
    expect(document.documentElement.dir).toBe('rtl');
    expect(document.documentElement.lang).toBe('ar');
  });

  it('flips direction in both directions as the language changes', async () => {
    mockApi({ profileLanguage: 'en' });

    renderApp();

    // Wait for the language to RESOLVE, not just for a direction to be
    // written: 'ltr' is also what the document reads before anything loads.
    await waitFor(() => expect(screen.getByTestId('lang')).toHaveTextContent('en'));
    expect(document.documentElement.dir).toBe('ltr');

    await act(async () => {
      screen.getByText('to-ar').click();
    });
    await waitFor(() => expect(document.documentElement.dir).toBe('rtl'));

    await act(async () => {
      screen.getByText('to-en').click();
    });
    await waitFor(() => expect(document.documentElement.dir).toBe('ltr'));
  });

  /**
   * The retired toggle wrote its own localStorage key, deliberately
   * independent of the language. A returning user holding a stale 'rtl' there
   * while their language is English must not be stuck mirrored — with no
   * toggle left, they would have no way out. The language wins and the key is
   * cleared.
   */
  it('ignores and clears a stale direction left by the retired toggle', async () => {
    localStorage.setItem(RETIRED_DIRECTION_KEY, 'rtl');
    mockApi({ profileLanguage: 'en' });

    renderApp();

    await waitFor(() => expect(document.documentElement.dir).toBe('ltr'));
    expect(screen.getByTestId('dir')).toHaveTextContent('ltr');
    expect(localStorage.getItem(RETIRED_DIRECTION_KEY)).toBeNull();
  });

  /**
   * The per-user preference lives on the PROFILE, not in the browser: a reload
   * restores it from GET /api/v1/settings/language, so it follows the user to
   * any device. Here local storage says English and the profile says Arabic —
   * the profile must win.
   */
  it('restores the per-user preference from the profile, not from local storage', async () => {
    mockApi({ profileLanguage: 'en' });
    const first = renderApp();
    await waitFor(() => expect(screen.getByTestId('lang')).toHaveTextContent('en'));

    // The user switches to Arabic; the PATCH persists it to the profile.
    await act(async () => {
      screen.getByText('to-ar').click();
    });
    await waitFor(() => expect(document.documentElement.dir).toBe('rtl'));
    expect(global.fetch).toHaveBeenCalledWith(
      '/api/v1/settings/language',
      expect.objectContaining({ method: 'PATCH', body: JSON.stringify({ language_code: 'ar' }) })
    );

    first.unmount();

    // Reload with local storage disagreeing with the profile: the PROFILE wins.
    localStorage.setItem('i18n_language', 'en');
    mockApi({ profileLanguage: 'ar' });
    renderApp();

    await waitFor(() => expect(screen.getByTestId('lang')).toHaveTextContent('ar'));
    expect(document.documentElement.dir).toBe('rtl');
  });

  /**
   * A signed-out visitor has no profile, so the public screens fall back to the
   * code remembered locally — otherwise sign-in would always be English for a
   * user who runs the rest of the interface in Arabic.
   */
  it('falls back to the locally remembered language when signed out', async () => {
    localStorage.setItem('i18n_language', 'ar');
    (global.fetch as jest.Mock).mockImplementation((url: string) => {
      if (url === '/api/v1/languages') {
        return Promise.resolve({ ok: true, status: 200, json: async () => ({ languages: LANGUAGES }) });
      }
      if (url === '/api/v1/settings/language') {
        return Promise.resolve({ ok: false, status: 403, json: async () => ({}) });
      }
      return Promise.resolve({ ok: true, status: 200, json: async () => ({ translations: {} }) });
    });

    renderApp();

    await waitFor(() => expect(screen.getByTestId('lang')).toHaveTextContent('ar'));
    expect(document.documentElement.dir).toBe('rtl');
  });

  /**
   * A third right-to-left language is DATA. Nothing in the client names 'he'.
   */
  it('mirrors for a language the client has never heard of', async () => {
    const withHebrew = [...LANGUAGES, { code: 'he', name: 'עברית', direction: 'rtl' }];
    (global.fetch as jest.Mock).mockImplementation((url: string) => {
      if (url === '/api/v1/languages') {
        return Promise.resolve({ ok: true, status: 200, json: async () => ({ languages: withHebrew }) });
      }
      if (url === '/api/v1/settings/language') {
        return Promise.resolve({
          ok: true,
          status: 200,
          json: async () => ({ language_code: 'he', available_languages: withHebrew }),
        });
      }
      return Promise.resolve({ ok: true, status: 200, json: async () => ({ translations: {} }) });
    });

    renderApp();

    await waitFor(() => expect(document.documentElement.dir).toBe('rtl'));
    expect(screen.getByTestId('lang')).toHaveTextContent('he');
  });
});
