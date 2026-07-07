/**
 * WC-235 — Registration "pending approval" confirmation.
 *
 * When ADMIN_APPROVAL_ENFORCED is on, POST /api/v1/register returns 201 with
 * approval_required=true. The page must show a pending-approval confirmation
 * and NOT chain a login (which would be refused for the invited owner).
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { useRouter } from 'next/navigation';

jest.mock('next/navigation', () => ({
  useRouter: jest.fn(),
}));

const refreshAuth = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ isAuthenticated: () => false, isLoading: false, refreshAuth }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

jest.mock('@/lib/branding-context', () => ({
  useBranding: () => ({ siteName: 'Whity', logoWideUrl: null, logoSquareUrl: null, faviconUrl: null }),
}));

import RegisterPage from '@/app/register/page';

const mockRouter = { push: jest.fn() };

beforeEach(() => {
  jest.clearAllMocks();
  (useRouter as jest.Mock).mockReturnValue(mockRouter);
  global.fetch = jest.fn();
});

function fillAndSubmit() {
  fireEvent.change(screen.getByPlaceholderText('Acme Inc'), { target: { value: 'Acme Inc' } });
  fireEvent.change(screen.getByPlaceholderText('you@example.com'), {
    target: { value: 'owner@acme.test' },
  });
  fireEvent.change(screen.getByPlaceholderText(/At least .* characters/i), {
    target: { value: 'a-strong-password' },
  });
  fireEvent.click(screen.getByRole('button', { name: /create workspace/i }));
}

test('shows the pending-approval confirmation and does not chain a login', async () => {
  // 201 with approval_required=true (admin approval enforced).
  (global.fetch as jest.Mock).mockResolvedValueOnce({
    status: 201,
    ok: true,
    json: async () => ({ data: { profile_id: 5, tenant_id: 9, approval_required: true } }),
  });

  render(<RegisterPage />);
  fillAndSubmit();

  await waitFor(() =>
    expect(screen.getByTestId('registration-pending-approval')).toBeInTheDocument()
  );

  // Exactly one fetch: the register POST. The login endpoint must NOT be called.
  expect(global.fetch).toHaveBeenCalledTimes(1);
  expect(global.fetch).toHaveBeenCalledWith('/api/v1/register', expect.anything());
  expect(mockRouter.push).not.toHaveBeenCalled();
});

test('auto-logs in when approval is not required (approval_required=false)', async () => {
  (global.fetch as jest.Mock)
    .mockResolvedValueOnce({
      status: 201,
      ok: true,
      json: async () => ({ data: { profile_id: 5, tenant_id: 9, approval_required: false } }),
    })
    .mockResolvedValueOnce({ ok: true, status: 200 }); // login

  render(<RegisterPage />);
  fillAndSubmit();

  await waitFor(() =>
    expect(global.fetch).toHaveBeenCalledWith('/api/v1/login', expect.anything())
  );
  expect(screen.queryByTestId('registration-pending-approval')).not.toBeInTheDocument();
});
