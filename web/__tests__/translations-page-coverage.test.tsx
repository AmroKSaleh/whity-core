/**
 * The Translations console must be able to answer "what still needs translating
 * for language X".
 *
 * It could not, and the reason is worth stating: a missing translation has no
 * ROW. Every listing in the system was therefore a listing of work already done,
 * and a language read as most complete exactly when nobody had started it. That
 * became the normal case the moment strings started being extracted from source
 * in bulk and seeded in English only — the gap IS the state of every language
 * except the source, and the person who closes it is a translator with no access
 * to the code.
 *
 * These tests pin the three things that make the gap visible: the coverage
 * panel's counts, a key with no row in the target language still appearing as a
 * row with its English source alongside, and picking a domain with missing keys
 * asking the server for exactly those.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mockApiGet = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: {
    GET: (...args: unknown[]) => mockApiGet(...args),
    POST: jest.fn(),
    PATCH: jest.fn(),
    DELETE: jest.fn(),
  },
}));

const mockUseAuth = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => mockUseAuth(),
}));

const hasPermission = jest.fn<boolean, [string]>();
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ hasPermission, loading: false, permissions: [] }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

import TranslationsPage from '@/app/(protected)/admin/translations/page';
import { TRANSLATIONS_MANAGE } from '@/lib/capabilities';

const COVERAGE = {
  source_language_code: 'en',
  languages: [
    {
      language_code: 'en',
      name: 'English',
      total: 115,
      translated: 115,
      missing: 0,
      domains: [{ domain: 'auth', total: 60, translated: 60, missing: 0 }],
    },
    {
      language_code: 'ar',
      name: 'العربية',
      total: 115,
      translated: 60,
      missing: 55,
      domains: [
        { domain: 'admin', total: 55, translated: 0, missing: 55 },
        { domain: 'auth', total: 60, translated: 60, missing: 0 },
      ],
    },
  ],
};

/** A key seeded in English that nobody has translated into Arabic yet. */
const UNTRANSLATED_ROWS = [
  {
    key: 'translations.title',
    system_default: null,
    tenant_override: null,
    source_text: 'Translations',
    translated: false,
  },
];

function grant(...perms: string[]) {
  hasPermission.mockImplementation((slug: string) => perms.includes(slug));
}

beforeEach(() => {
  jest.clearAllMocks();
  grant(TRANSLATIONS_MANAGE);
  mockUseAuth.mockReturnValue({ user: { tenant_id: 0 } });
  mockApiGet.mockImplementation((path: string) => {
    if (path === '/api/v1/translations/coverage') {
      return Promise.resolve({ data: { data: COVERAGE }, error: undefined });
    }
    return Promise.resolve({ data: { data: UNTRANSLATED_ROWS }, error: undefined });
  });
});

describe('Translations console — the untranslated gap', () => {
  it('reports how many strings each language is still missing', async () => {
    render(<TranslationsPage />);

    // The gap, stated as a number, for the language that has one.
    expect(await screen.findByText('55 missing')).toBeInTheDocument();
    expect(screen.getByText('60 of 115 translated')).toBeInTheDocument();
  });

  it('marks the source language as the source rather than as complete work', async () => {
    render(<TranslationsPage />);

    // English is not "100% translated by a translator" — it is where the words
    // come from. Labelling it as finished work would be a lie about who did it.
    expect(await screen.findByText('Source language')).toBeInTheDocument();
  });

  it('breaks the gap down by domain so a translator knows where to start', async () => {
    const user = userEvent.setup();
    render(<TranslationsPage />);

    await user.click(await screen.findByText('العربية (ar)'));

    await screen.findByText('admin');
    expect(screen.getByText('0 of 55 translated')).toBeInTheDocument();
  });

  it('lists a key that has NO row in this language, with the English it is translated from', async () => {
    const user = userEvent.setup();
    render(<TranslationsPage />);

    await user.click(await screen.findByText('العربية (ar)'));
    await user.click(await screen.findByText('admin'));

    // The key exists only in English; the Arabic side is empty and asking for it
    // is the whole point of the screen.
    await screen.findByText('translations.title');
    expect(screen.getByText('Translations', { selector: 'td' })).toBeInTheDocument();
    expect(screen.getByText('English source')).toBeInTheDocument();

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith(
        '/api/v1/translations',
        expect.objectContaining({
          params: {
            query: expect.objectContaining({
              language_code: 'ar',
              domain: 'admin',
              untranslated: '1',
            }),
          },
        })
      );
    });
  });

  it('does not offer an English-source column when English is what is being edited', async () => {
    render(<TranslationsPage />);

    await screen.findByText('Source language');
    expect(screen.queryByText('English source')).not.toBeInTheDocument();
  });
});
