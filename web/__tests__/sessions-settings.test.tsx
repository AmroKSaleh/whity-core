/**
 * WC-b-logout-others — SessionsSettings ("Sign out of all other sessions & devices").
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

const mockApiClient = jest.fn();
const refreshAuth = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient, refreshAuth }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

import { SessionsSettings } from '@/components/SessionsSettings';

beforeEach(() => {
  jest.clearAllMocks();
  mockApiClient.mockResolvedValue({ ok: true, json: async () => ({ user: {} }) });
});

async function openAndConfirm() {
  fireEvent.click(screen.getByTestId('logout-others-button'));
  fireEvent.click(await screen.findByTestId('logout-others-confirm'));
}

it('POSTs to /api/v1/me/logout-others, refreshes auth, and toasts success', async () => {
  render(<SessionsSettings />);
  await openAndConfirm();

  await waitFor(() =>
    expect(mockApiClient).toHaveBeenCalledWith('/api/v1/me/logout-others', { method: 'POST' })
  );
  await waitFor(() =>
    expect(addToast).toHaveBeenCalledWith(expect.stringContaining('Signed out'), 'success')
  );
  expect(refreshAuth).toHaveBeenCalled();
});

it('does not call the API until the action is confirmed', () => {
  render(<SessionsSettings />);
  fireEvent.click(screen.getByTestId('logout-others-button')); // opens dialog only
  expect(mockApiClient).not.toHaveBeenCalled();
});

it('surfaces a server error as an error toast and does not refresh auth', async () => {
  mockApiClient.mockResolvedValue({ ok: false, json: async () => ({ error: 'nope' }) });
  render(<SessionsSettings />);
  await openAndConfirm();

  await waitFor(() => expect(addToast).toHaveBeenCalledWith('nope', 'error'));
  expect(refreshAuth).not.toHaveBeenCalled();
});
