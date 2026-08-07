/**
 * WC-password-reset-2fa-recovery — password-reset landing page tests.
 *
 * Mirrors the token-consumption shape of verify-email/page.tsx, adapted for
 * this flow's form (new password + confirm), and the two possible success
 * outcomes (applied immediately vs staged for admin approval).
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

let mockToken: string | null = 'a-valid-token';
jest.mock('next/navigation', () => ({
  useSearchParams: () => ({ get: (key: string) => (key === 'token' ? mockToken : null) }),
}));

jest.mock('@/lib/branding-context', () => ({
  useBranding: () => ({ siteName: 'Whity', logoWideUrl: null, logoSquareUrl: null, faviconUrl: null }),
}));

import ResetPasswordPage from '@/app/reset-password/page';

beforeEach(() => {
  jest.clearAllMocks();
  mockToken = 'a-valid-token';
  global.fetch = jest.fn();
});

function fillAndSubmit(password: string, confirm = password) {
  fireEvent.change(screen.getByLabelText('New password'), { target: { value: password } });
  fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: confirm } });
  fireEvent.click(screen.getByRole('button', { name: /reset password/i }));
}

test('shows the no-token error state (with a link to request a new one) when there is no token', () => {
  mockToken = null;
  render(<ResetPasswordPage />);

  expect(screen.getByTestId('reset-password-error')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /request a new link/i })).toHaveAttribute(
    'href',
    '/forgot-password'
  );
});

test('applies the new password immediately and shows the "applied" success state', async () => {
  (global.fetch as jest.Mock).mockResolvedValueOnce({
    status: 200,
    json: async () => ({ data: { status: 'applied', message: 'ok' } }),
  });

  render(<ResetPasswordPage />);
  fillAndSubmit('a-strong-new-password');

  await waitFor(() => expect(screen.getByTestId('reset-password-applied')).toBeInTheDocument());
  expect(global.fetch).toHaveBeenCalledWith(
    '/api/v1/auth/password/reset',
    expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ token: 'a-valid-token', password: 'a-strong-new-password' }),
    })
  );
});

test('shows the awaiting-approval state when the backend stages the reset', async () => {
  (global.fetch as jest.Mock).mockResolvedValueOnce({
    status: 200,
    json: async () => ({ data: { status: 'awaiting_approval', message: 'ok' } }),
  });

  render(<ResetPasswordPage />);
  fillAndSubmit('a-strong-new-password');

  await waitFor(() =>
    expect(screen.getByTestId('reset-password-awaiting-approval')).toBeInTheDocument()
  );
});

test('shows a generic error for an invalid/expired token (400)', async () => {
  (global.fetch as jest.Mock).mockResolvedValueOnce({ status: 400, json: async () => ({}) });

  render(<ResetPasswordPage />);
  fillAndSubmit('a-strong-new-password');

  await waitFor(() => expect(screen.getByTestId('reset-password-error')).toBeInTheDocument());
});

test('rejects mismatched passwords client-side without calling the API', () => {
  render(<ResetPasswordPage />);
  fillAndSubmit('a-strong-new-password', 'a-different-password');

  expect(screen.getByText('Passwords do not match')).toBeInTheDocument();
  expect(global.fetch).not.toHaveBeenCalled();
});

test('rejects a too-short password client-side without calling the API', () => {
  render(<ResetPasswordPage />);
  fillAndSubmit('short');

  expect(screen.getByText(/at least 8 characters/i)).toBeInTheDocument();
  expect(global.fetch).not.toHaveBeenCalled();
});
