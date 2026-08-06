/**
 * #409 — DevicesSettings list UI (fetch, per-row revoke), the "Devices" half
 * of the sessions/devices "two lists" design.
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

import { DevicesSettings } from '@/components/DevicesSettings';

const DEVICES = [
  {
    id: 1,
    name: "Amro's Phone",
    platform: 'ios',
    last_seen_at: '2026-07-07 09:00:00',
    expires_at: '2026-10-01 00:00:00',
    created_at: '2026-07-01 10:00:00',
  },
  {
    // Reported bug fixture: a bare framework identifier as the client-supplied
    // name — must never render on its own.
    id: 2,
    name: 'flutter',
    platform: 'android',
    last_seen_at: null,
    expires_at: '2026-10-02 00:00:00',
    created_at: '2026-07-02 10:00:00',
  },
];

/** Route apiClient responses by method + path. */
function wire(list = DEVICES) {
  mockApiClient.mockImplementation((url: string, opts?: { method?: string }) => {
    const method = opts?.method ?? 'GET';
    if (url === '/api/v1/devices' && method === 'GET') {
      return Promise.resolve({ ok: true, status: 200, json: async () => ({ devices: list }) });
    }
    if (url.startsWith('/api/v1/devices/') && method === 'DELETE') {
      return Promise.resolve({ ok: true, status: 204, json: async () => ({}) });
    }
    return Promise.resolve({ ok: false, status: 404, json: async () => ({}) });
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  wire();
});

it('lists devices with a friendly platform + name label', async () => {
  render(<DevicesSettings />);
  await waitFor(() => expect(screen.getByTestId('device-row-1')).toBeInTheDocument());

  expect(screen.getByText("iPhone/iPad — Amro's Phone")).toBeInTheDocument();
});

it('never renders a bare/raw client-supplied name (the "flutter" bug)', async () => {
  render(<DevicesSettings />);
  await waitFor(() => expect(screen.getByTestId('device-row-2')).toBeInTheDocument());

  // Falls back to the platform-derived label instead of the raw "flutter" string.
  expect(screen.getByText('Android device')).toBeInTheDocument();
  expect(screen.queryByText('flutter')).not.toBeInTheDocument();
});

it('revokes a single device and removes its row', async () => {
  render(<DevicesSettings />);
  await waitFor(() => expect(screen.getByTestId('device-revoke-1')).toBeInTheDocument());

  fireEvent.click(screen.getByTestId('device-revoke-1'));
  await waitFor(() =>
    expect(mockApiClient).toHaveBeenCalledWith('/api/v1/devices/1', { method: 'DELETE' })
  );
  await waitFor(() => expect(screen.queryByTestId('device-row-1')).not.toBeInTheDocument());
  expect(addToast).toHaveBeenCalledWith('Device removed.', 'success');
});

it('shows an empty state when there are no devices', async () => {
  wire([]);
  render(<DevicesSettings />);
  await waitFor(() => expect(screen.getByTestId('devices-empty')).toBeInTheDocument());
});

it('shows a load error with retry', async () => {
  mockApiClient.mockResolvedValue({ ok: false, status: 500, json: async () => ({}) });
  render(<DevicesSettings />);
  await waitFor(() => expect(screen.getByTestId('devices-load-error')).toBeInTheDocument());
});
