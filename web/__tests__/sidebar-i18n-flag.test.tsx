/**
 * The i18n feature flag, at the place a USER meets it: the sidebar.
 *
 * "Remove the buttons to remove the confusion" is the whole user-facing half of
 * this flag, so it is asserted against the REAL sidebar rather than against the
 * switcher in isolation — the row has its own frame and globe icon, and an
 * empty bordered box left behind by a self-suppressing control would satisfy
 * every component-level test while failing the actual requirement.
 *
 * The provider is real and its calls are routed by URL (the same technique as
 * direction-from-language.test.tsx): what is under test is the wiring from the
 * server's `i18n_enabled` to the rendered chrome, so stubbing the hook would
 * test nothing.
 */

import React from 'react';
import { act, render, screen, waitFor } from '@testing-library/react';
import { LanguageProvider } from '@amroksaleh/features/i18n';

jest.mock('next/navigation', () => ({
  usePathname: () => '/dashboard',
  useRouter: () => ({ push: jest.fn() }),
}));

jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({
    logout: jest.fn(),
    user: { id: 1, email: 'someone@example.com', tenant_id: 0, role: 'admin' },
    memberships: [],
    switchTenant: jest.fn(),
  }),
}));

jest.mock('@/lib/navigation-context', () => ({
  useNavigation: () => ({ items: [], getGroupedItems: () => [], refresh: jest.fn() }),
}));

jest.mock('@/lib/branding-context', () => ({
  useBranding: () => ({
    siteName: 'Test',
    logoWideUrl: null,
    logoSquareUrl: null,
    faviconUrl: null,
  }),
}));

jest.mock('@/lib/theme-mode-context', () => ({
  useThemeMode: () => ({ resolved: 'light', toggle: jest.fn() }),
}));

jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast: jest.fn() }),
}));

import { Sidebar } from '@/components/sidebar';

const LANGUAGES = [
  { code: 'en', name: 'English', direction: 'ltr' },
  { code: 'ar', name: 'العربية', direction: 'rtl' },
];

/**
 * A backend with i18n in the given state, and a signed-in profile whose stored
 * language is Arabic — the case where hiding the switcher actually costs the
 * user something, and so the one worth pinning.
 */
function mockBackend({ i18nEnabled }: { i18nEnabled: boolean }) {
  (global.fetch as jest.Mock).mockImplementation((url: string) => {
    if (url === '/api/v1/languages') {
      return Promise.resolve({
        ok: true,
        status: 200,
        json: async () => ({ languages: LANGUAGES, i18n_enabled: i18nEnabled }),
      });
    }
    if (url === '/api/v1/settings/language') {
      return Promise.resolve({
        ok: true,
        status: 200,
        json: async () => ({
          language_code: i18nEnabled ? 'ar' : null,
          available_languages: LANGUAGES,
          i18n_enabled: i18nEnabled,
        }),
      });
    }
    return Promise.resolve({ ok: true, status: 200, json: async () => ({ translations: {} }) });
  });
}

function renderSidebar() {
  return render(
    <LanguageProvider defaultLanguage="en">
      <Sidebar />
    </LanguageProvider>
  );
}

describe('sidebar language affordance follows the i18n feature flag', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.clear();
    global.fetch = jest.fn();
  });

  it('shows the language row when the instance offers a choice', async () => {
    mockBackend({ i18nEnabled: true });

    renderSidebar();

    await waitFor(() => expect(screen.getByTestId('language-switcher')).toBeInTheDocument());
    expect(screen.getByRole('combobox')).toBeInTheDocument();
  });

  it('shows no language row at all when the flag is off', async () => {
    mockBackend({ i18nEnabled: false });

    renderSidebar();

    // Wait for something the sidebar renders unconditionally, so this is an
    // assertion about the settled tree rather than about an unrendered one.
    await waitFor(() => expect(screen.getByTestId('theme-toggle')).toBeInTheDocument());

    expect(screen.queryByTestId('language-switcher')).not.toBeInTheDocument();
    // Not merely the <select>: the frame and its globe icon go too, so no empty
    // box is left where the control used to be.
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
  });

  /**
   * The flag never paints and then retracts. A user of a single-language
   * deployment must not see a language control flash past on every page load —
   * that is worse than the control itself, which at least did something.
   */
  it('never flashes the row before the instance has answered', async () => {
    mockBackend({ i18nEnabled: false });

    renderSidebar();

    // Synchronously, before any fetch has resolved.
    expect(screen.queryByTestId('language-switcher')).not.toBeInTheDocument();

    // Let the provider settle so its state updates land inside the test.
    await act(async () => {});
    expect(screen.queryByTestId('language-switcher')).not.toBeInTheDocument();
  });
});
