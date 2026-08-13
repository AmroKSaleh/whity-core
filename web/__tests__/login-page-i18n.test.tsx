import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { useRouter } from 'next/navigation';
import LoginPage from '@/app/login/page';
import { AuthProvider } from '@/lib/auth-context';
import { ToastProvider } from '@/lib/toast-context';
import { LanguageProvider } from '@amroksaleh/features/i18n';

/**
 * The sign-in screen is the first one converted to real translations, and the
 * template for every screen after it. These tests pin the two properties that
 * make the pattern safe to fan out:
 *
 *  - with a bundle present, the screen renders the TRANSLATED strings;
 *  - with a key missing, it renders the ENGLISH FALLBACK passed at the call
 *    site, never a raw key — so a half-seeded domain degrades to English
 *    rather than to `login.submit`.
 */

jest.mock('next/navigation', () => ({ useRouter: jest.fn() }));

// The federated-login buttons fetch their own provider list; irrelevant here.
jest.mock('@/components/sso-login-buttons', () => ({ SsoLoginButtons: () => null }));

const LANGUAGES = [
  { code: 'en', name: 'English', direction: 'ltr' },
  { code: 'ar', name: 'العربية', direction: 'rtl' },
];

/** A partial Arabic `auth` bundle — deliberately missing `login.submit`. */
const ARABIC_AUTH = {
  'login.subtitle': 'سجّل الدخول إلى حسابك للمتابعة',
  'login.email.label': 'البريد الإلكتروني',
  'login.email.placeholder': 'أدخل بريدك الإلكتروني',
  'login.password.label': 'كلمة المرور',
  'login.welcome': 'مرحباً بك في {site}',
};

function mockApi(bundle: Record<string, string>, language: string) {
  (global.fetch as jest.Mock).mockImplementation((url: string) => {
    if (url === '/api/v1/languages') {
      return Promise.resolve({ ok: true, status: 200, json: async () => ({ languages: LANGUAGES }) });
    }
    if (url === '/api/v1/settings/language') {
      return Promise.resolve({
        ok: true,
        status: 200,
        json: async () => ({ language_code: language, available_languages: LANGUAGES }),
      });
    }
    if (url === `/api/v1/translations/${language}/auth`) {
      return Promise.resolve({ ok: true, status: 200, json: async () => ({ translations: bundle }) });
    }
    // Unauthenticated: /api/v1/me, /api/v1/auth/refresh.
    return Promise.resolve({ ok: false, status: 401, json: async () => ({}) });
  });
}

function renderLogin() {
  return render(
    <AuthProvider>
      <LanguageProvider>
        <ToastProvider>
          <LoginPage />
        </ToastProvider>
      </LanguageProvider>
    </AuthProvider>
  );
}

describe('sign-in screen translations', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.clear();
    (useRouter as jest.Mock).mockReturnValue({ push: jest.fn() });
    global.fetch = jest.fn();
  });

  it('renders Arabic when the resolved language is Arabic', async () => {
    mockApi(ARABIC_AUTH, 'ar');

    renderLogin();

    await waitFor(() => {
      expect(screen.getByText('سجّل الدخول إلى حسابك للمتابعة')).toBeInTheDocument();
    });
    expect(screen.getByText('البريد الإلكتروني')).toBeInTheDocument();
    expect(screen.getByPlaceholderText('أدخل بريدك الإلكتروني')).toBeInTheDocument();
    expect(screen.getByText('كلمة المرور')).toBeInTheDocument();
  });

  it('substitutes placeholders inside a translated sentence', async () => {
    mockApi(ARABIC_AUTH, 'ar');

    renderLogin();

    // The whole sentence is one translatable unit with a {site} hole in it, so
    // the site name lands wherever Arabic word order puts it.
    await waitFor(() => {
      expect(screen.getByText(/مرحباً بك في/)).toBeInTheDocument();
    });
  });

  it('falls back to the English source string for a key the bundle is missing', async () => {
    mockApi(ARABIC_AUTH, 'ar');

    renderLogin();

    await waitFor(() => {
      expect(screen.getByText('البريد الإلكتروني')).toBeInTheDocument();
    });

    // `login.submit` is absent from the bundle above: the button reads the
    // English fallback, never the raw key.
    expect(screen.getByRole('button', { name: 'Sign in' })).toBeInTheDocument();
    expect(screen.queryByText('login.submit')).toBeNull();
  });

  it('renders English when the bundle is unavailable entirely', async () => {
    (global.fetch as jest.Mock).mockImplementation((url: string) => {
      if (url === '/api/v1/languages') {
        return Promise.resolve({ ok: true, status: 200, json: async () => ({ languages: LANGUAGES }) });
      }
      return Promise.resolve({ ok: false, status: 401, json: async () => ({}) });
    });

    renderLogin();

    await waitFor(() => {
      expect(screen.getByText('Sign in to your account to continue')).toBeInTheDocument();
    });
    expect(screen.getByPlaceholderText('Enter your email')).toBeInTheDocument();
  });
});
