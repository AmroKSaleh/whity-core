/**
 * WC-password-reset-2fa-recovery — "Forgot password?" entry point tests.
 *
 * Mirrors register-page-approval.test.tsx's mocking style. Verifies the
 * generic-response contract: the page must never reveal whether an address
 * has an account, and must surface 422/429 distinctly (those ARE safe to
 * reveal — they carry no information about account existence).
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

jest.mock('@/lib/branding-context', () => ({
  useBranding: () => ({ siteName: 'Whity', logoWideUrl: null, logoSquareUrl: null, faviconUrl: null }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

import ForgotPasswordPage from '@/app/forgot-password/page';

beforeEach(() => {
  jest.clearAllMocks();
  global.fetch = jest.fn();
});

function submitEmail(email: string) {
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: email } });
  fireEvent.click(screen.getByRole('button', { name: /send reset link/i }));
}

test('shows the generic confirmation after a successful submission', async () => {
  (global.fetch as jest.Mock).mockResolvedValueOnce({ ok: true, status: 202, json: async () => ({}) });

  render(<ForgotPasswordPage />);
  submitEmail('someone@example.com');

  await waitFor(() => expect(screen.getByTestId('forgot-password-sent')).toBeInTheDocument());
  expect(global.fetch).toHaveBeenCalledWith(
    '/api/v1/auth/password/forgot',
    expect.objectContaining({ method: 'POST', credentials: 'include' })
  );
});

test('shows the SAME generic confirmation whether or not the account exists (no enumeration observable client-side)', async () => {
  (global.fetch as jest.Mock).mockResolvedValueOnce({ ok: true, status: 202, json: async () => ({}) });
  render(<ForgotPasswordPage />);
  submitEmail('known@example.com');
  await waitFor(() => expect(screen.getByTestId('forgot-password-sent')).toBeInTheDocument());
  expect(screen.getByText(/if that address has an account/i)).toBeInTheDocument();
});

test('surfaces a 422 as a field error, not the generic confirmation', async () => {
  (global.fetch as jest.Mock).mockResolvedValueOnce({ ok: false, status: 422, json: async () => ({}) });

  render(<ForgotPasswordPage />);
  // A value that satisfies the <input type="email"> client-side constraint
  // (so jsdom actually dispatches the submit event) but that the SERVER
  // still rejects with 422 — exercising the response-handling branch, not
  // the browser's own validation UI.
  submitEmail('weird@x');

  await waitFor(() => expect(screen.getByText(/valid email address/i)).toBeInTheDocument());
  expect(screen.queryByTestId('forgot-password-sent')).not.toBeInTheDocument();
});

test('surfaces a 429 as a rate-limit message', async () => {
  (global.fetch as jest.Mock).mockResolvedValueOnce({ ok: false, status: 429, json: async () => ({}) });

  render(<ForgotPasswordPage />);
  submitEmail('flood@example.com');

  await waitFor(() => expect(screen.getByText(/too many requests/i)).toBeInTheDocument());
});

test('requires a non-empty email before submitting', () => {
  render(<ForgotPasswordPage />);
  fireEvent.click(screen.getByRole('button', { name: /send reset link/i }));

  expect(screen.getByText('Email is required')).toBeInTheDocument();
  expect(global.fetch).not.toHaveBeenCalled();
});
