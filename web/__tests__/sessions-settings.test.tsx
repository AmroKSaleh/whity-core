/**
 * WC-f-sessions-table — SessionsSettings list UI (fetch, per-row revoke,
 * revoke-all-others, and the "everywhere incl devices" epoch action).
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

const SESSIONS = [
  {
    id: 1,
    user_agent: 'Mozilla/5.0 (Macintosh)',
    ip_address: '10.0.0.9',
    created_at: '2026-07-01 10:00:00',
    last_seen_at: '2026-07-07 09:00:00',
    current: true,
  },
  {
    id: 2,
    user_agent: 'Mozilla/5.0 (Windows)',
    ip_address: '10.0.0.8',
    created_at: '2026-07-02 10:00:00',
    last_seen_at: '2026-07-06 09:00:00',
    current: false,
  },
];

/** Route apiClient responses by method + path. */
function wire(list = SESSIONS) {
  mockApiClient.mockImplementation((url: string, opts?: { method?: string }) => {
    const method = opts?.method ?? 'GET';
    if (url === '/api/v1/me/sessions' && method === 'GET') {
      return Promise.resolve({ ok: true, status: 200, json: async () => ({ sessions: list }) });
    }
    if (url === '/api/v1/me/sessions' && method === 'DELETE') {
      return Promise.resolve({ ok: true, status: 200, json: async () => ({ revoked: 1 }) });
    }
    if (url.startsWith('/api/v1/me/sessions/') && method === 'DELETE') {
      return Promise.resolve({ ok: true, status: 204, json: async () => ({}) });
    }
    if (url === '/api/v1/me/logout-others' && method === 'POST') {
      return Promise.resolve({ ok: true, status: 200, json: async () => ({}) });
    }
    return Promise.resolve({ ok: false, status: 404, json: async () => ({}) });
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  wire();
});

it('lists sessions, flags the current one, and shows revoke only on others', async () => {
  render(<SessionsSettings />);
  await waitFor(() => expect(screen.getByTestId('session-row-1')).toBeInTheDocument());

  expect(screen.getByTestId('session-current-badge')).toBeInTheDocument();
  // Current session (id 1) has no revoke button; the other (id 2) does.
  expect(screen.queryByTestId('session-revoke-1')).not.toBeInTheDocument();
  expect(screen.getByTestId('session-revoke-2')).toBeInTheDocument();
});

it('revokes a single session and removes its row', async () => {
  render(<SessionsSettings />);
  await waitFor(() => expect(screen.getByTestId('session-revoke-2')).toBeInTheDocument());

  fireEvent.click(screen.getByTestId('session-revoke-2'));
  await waitFor(() =>
    expect(mockApiClient).toHaveBeenCalledWith('/api/v1/me/sessions/2', { method: 'DELETE' })
  );
  await waitFor(() => expect(screen.queryByTestId('session-row-2')).not.toBeInTheDocument());
});

it('signs out all other sessions (session-scoped DELETE)', async () => {
  render(<SessionsSettings />);
  await waitFor(() => expect(screen.getByTestId('sessions-revoke-others')).toBeInTheDocument());

  fireEvent.click(screen.getByTestId('sessions-revoke-others'));
  await waitFor(() =>
    expect(mockApiClient).toHaveBeenCalledWith('/api/v1/me/sessions', { method: 'DELETE' })
  );
  expect(addToast).toHaveBeenCalledWith(expect.stringContaining('all other sessions'), 'success');
});

it('signs out everywhere (epoch) after confirm, then refreshes auth', async () => {
  render(<SessionsSettings />);
  await waitFor(() => expect(screen.getByTestId('sessions-logout-everywhere')).toBeInTheDocument());

  fireEvent.click(screen.getByTestId('sessions-logout-everywhere'));
  fireEvent.click(await screen.findByTestId('sessions-logout-everywhere-confirm'));

  await waitFor(() =>
    expect(mockApiClient).toHaveBeenCalledWith('/api/v1/me/logout-others', { method: 'POST' })
  );
  expect(refreshAuth).toHaveBeenCalled();
});

it('shows an empty state when there are no sessions', async () => {
  wire([]);
  render(<SessionsSettings />);
  await waitFor(() => expect(screen.getByTestId('sessions-empty')).toBeInTheDocument());
});
