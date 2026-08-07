/**
 * WC-password-reset-2fa-recovery — "I lost my 2FA device" account-recovery
 * page tests. Mirrors verify-email/page.tsx's two-mode shape (no-token
 * request form / token auto-confirm) adapted for this flow.
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

let mockToken: string | null = null;
jest.mock('next/navigation', () => ({
  useSearchParams: () => ({ get: (key: string) => (key === 'token' ? mockToken : null) }),
}));

jest.mock('@/lib/branding-context', () => ({
  useBranding: () => ({ siteName: 'Whity', logoWideUrl: null, logoSquareUrl: null, faviconUrl: null }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

import AccountRecoveryPage from '@/app/account-recovery/page';

beforeEach(() => {
  jest.clearAllMocks();
  mockToken = null;
  global.fetch = jest.fn();
});

// ---------------------------------------------------------------------------
// Request form (no token)
// ---------------------------------------------------------------------------

function submitRequest(email: string) {
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: email } });
  fireEvent.click(screen.getByRole('button', { name: /request recovery/i }));
}

test('shows the request form when there is no token, and a generic confirmation on submit', async () => {
  (global.fetch as jest.Mock).mockResolvedValueOnce({ ok: true, status: 202, json: async () => ({}) });

  render(<AccountRecoveryPage />);
  expect(screen.getByTestId('account-recovery-form')).toBeInTheDocument();

  submitRequest('someone@example.com');

  await waitFor(() => expect(screen.getByTestId('account-recovery-request-sent')).toBeInTheDocument());
  expect(global.fetch).toHaveBeenCalledWith(
    '/api/v1/auth/2fa-recovery/request',
    expect.objectContaining({ method: 'POST', credentials: 'include' })
  );
});

test('the request form never distinguishes account existence in its response handling (202 either way)', async () => {
  (global.fetch as jest.Mock).mockResolvedValueOnce({ ok: true, status: 202, json: async () => ({}) });
  render(<AccountRecoveryPage />);
  submitRequest('known@example.com');
  await waitFor(() => expect(screen.getByTestId('account-recovery-request-sent')).toBeInTheDocument());
  expect(screen.getByText(/if that address has an account/i)).toBeInTheDocument();
});

test('surfaces a 429 as a rate-limit message on the request form', async () => {
  (global.fetch as jest.Mock).mockResolvedValueOnce({ ok: false, status: 429, json: async () => ({}) });

  render(<AccountRecoveryPage />);
  submitRequest('flood@example.com');

  await waitFor(() => expect(screen.getByText(/too many requests/i)).toBeInTheDocument());
});

// ---------------------------------------------------------------------------
// Token confirmation
// ---------------------------------------------------------------------------

test('auto-confirms the token on mount and shows the submitted state', async () => {
  mockToken = 'a-valid-token';
  (global.fetch as jest.Mock).mockResolvedValueOnce({ status: 200, json: async () => ({}) });

  render(<AccountRecoveryPage />);

  await waitFor(() => expect(screen.getByTestId('account-recovery-submitted')).toBeInTheDocument());
  expect(global.fetch).toHaveBeenCalledTimes(1);
  expect(global.fetch).toHaveBeenCalledWith(
    '/api/v1/auth/2fa-recovery/confirm',
    expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ token: 'a-valid-token' }),
    })
  );
});

test('confirming a token never submits a password field (no such input exists on this page)', async () => {
  mockToken = 'a-valid-token';
  (global.fetch as jest.Mock).mockResolvedValueOnce({ status: 200, json: async () => ({}) });

  render(<AccountRecoveryPage />);
  await waitFor(() => expect(screen.getByTestId('account-recovery-submitted')).toBeInTheDocument());

  expect(screen.queryByLabelText(/password/i)).not.toBeInTheDocument();
});

test('shows a generic error and falls back to the request form for an invalid/expired token', async () => {
  mockToken = 'expired-token';
  (global.fetch as jest.Mock).mockResolvedValueOnce({ status: 400, json: async () => ({}) });

  render(<AccountRecoveryPage />);

  await waitFor(() => expect(screen.getByTestId('account-recovery-form')).toBeInTheDocument());
});
