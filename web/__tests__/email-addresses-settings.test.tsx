/**
 * WC-54fb5c37 — EmailAddressesSettings component tests.
 *
 * Mocks useAuth (apiClient) and useToast, mirroring SessionsSettings'
 * conventions — no server, no AuthProvider needed.
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

// A STABLE reference across renders — the real useAuth() wraps apiClient in
// useCallback, so a mock that recreates a fresh wrapper function on every
// call (unlike the real one) breaks the component's load-effect dependency
// array, causing a refetch-on-every-render that can resurrect
// optimistically-removed local state. Pass the jest.fn() itself, not a new
// arrow function wrapping it.
const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

import { EmailAddressesSettings } from '@/components/EmailAddressesSettings';

interface ProfileEmail {
  id: number;
  email: string;
  verified: boolean;
  isPrimary: boolean;
  createdAt: string;
}

function jsonResponse(status: number, body: unknown) {
  return Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  });
}

const PRIMARY: ProfileEmail = {
  id: 1,
  email: 'primary@example.com',
  verified: true,
  isPrimary: true,
  createdAt: '2026-01-01T00:00:00Z',
};

const SECONDARY_UNVERIFIED: ProfileEmail = {
  id: 2,
  email: 'secondary@example.com',
  verified: false,
  isPrimary: false,
  createdAt: '2026-01-02T00:00:00Z',
};

beforeEach(() => {
  jest.clearAllMocks();
});

describe('EmailAddressesSettings — list rendering', () => {
  it('renders emails with primary/unverified badges', async () => {
    mockApiClient.mockImplementation(() => jsonResponse(200, { data: [PRIMARY, SECONDARY_UNVERIFIED] }));

    render(<EmailAddressesSettings />);

    await waitFor(() => expect(screen.getByTestId('emails-list')).toBeInTheDocument());
    expect(screen.getByText('primary@example.com')).toBeInTheDocument();
    expect(screen.getByText('secondary@example.com')).toBeInTheDocument();
    expect(screen.getByTestId('email-primary-badge')).toBeInTheDocument();
    expect(screen.getByTestId('email-unverified-badge-2')).toBeInTheDocument();
  });

  it('shows a load error with a retry affordance', async () => {
    mockApiClient.mockImplementation(() => jsonResponse(500, {}));

    render(<EmailAddressesSettings />);

    await waitFor(() => expect(screen.getByTestId('emails-load-error')).toBeInTheDocument());
  });
});

describe('EmailAddressesSettings — remove gating (never present a rejected action)', () => {
  it('disables Remove for the primary email when another email exists', async () => {
    mockApiClient.mockImplementation(() => jsonResponse(200, { data: [PRIMARY, SECONDARY_UNVERIFIED] }));

    render(<EmailAddressesSettings />);

    await waitFor(() => expect(screen.getByTestId('email-remove-1')).toBeInTheDocument());
    expect(screen.getByTestId('email-remove-1')).toBeDisabled();
    expect(screen.getByTestId('email-remove-2')).not.toBeDisabled();
  });

  it('disables Remove for the only email address', async () => {
    mockApiClient.mockImplementation(() => jsonResponse(200, { data: [PRIMARY] }));

    render(<EmailAddressesSettings />);

    await waitFor(() => expect(screen.getByTestId('email-remove-1')).toBeInTheDocument());
    expect(screen.getByTestId('email-remove-1')).toBeDisabled();
  });
});

describe('EmailAddressesSettings — actions', () => {
  it('adds a new email address and shows a success toast', async () => {
    mockApiClient.mockImplementation((url: string, opts?: { method?: string }) => {
      if (opts?.method === 'POST' && url === '/api/v1/me/emails') {
        return jsonResponse(201, { data: { ...SECONDARY_UNVERIFIED } });
      }
      return jsonResponse(200, { data: [PRIMARY] });
    });

    render(<EmailAddressesSettings />);
    await waitFor(() => expect(screen.getByTestId('email-add-input')).toBeInTheDocument());

    fireEvent.change(screen.getByTestId('email-add-input'), { target: { value: 'new@example.com' } });
    fireEvent.click(screen.getByTestId('email-add-submit'));

    await waitFor(() => {
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/me/emails',
        expect.objectContaining({ method: 'POST', body: JSON.stringify({ email: 'new@example.com' }) })
      );
    });
    await waitFor(() => expect(addToast).toHaveBeenCalledWith(expect.stringContaining('Verification'), 'success'));
  });

  it('resends verification for an unverified email', async () => {
    mockApiClient.mockImplementation((url: string, opts?: { method?: string }) => {
      if (opts?.method === 'POST' && url === '/api/v1/me/emails/2/resend-verification') {
        return jsonResponse(202, { data: { message: 'Verification email sent' } });
      }
      return jsonResponse(200, { data: [PRIMARY, SECONDARY_UNVERIFIED] });
    });

    render(<EmailAddressesSettings />);
    await waitFor(() => expect(screen.getByTestId('email-resend-2')).toBeInTheDocument());

    fireEvent.click(screen.getByTestId('email-resend-2'));

    await waitFor(() => expect(addToast).toHaveBeenCalledWith(expect.stringContaining('sent'), 'success'));
  });

  it('sets a verified secondary email as primary', async () => {
    const verifiedSecondary: ProfileEmail = { ...SECONDARY_UNVERIFIED, verified: true };
    mockApiClient.mockImplementation((url: string, opts?: { method?: string }) => {
      if (opts?.method === 'POST' && url === '/api/v1/me/emails/2/set-primary') {
        return jsonResponse(200, { data: { ...verifiedSecondary, isPrimary: true } });
      }
      return jsonResponse(200, { data: [PRIMARY, verifiedSecondary] });
    });

    render(<EmailAddressesSettings />);
    await waitFor(() => expect(screen.getByTestId('email-set-primary-2')).toBeInTheDocument());

    fireEvent.click(screen.getByTestId('email-set-primary-2'));

    await waitFor(() => expect(addToast).toHaveBeenCalledWith(expect.stringContaining('Primary'), 'success'));
  });

  it('removes a non-primary email', async () => {
    mockApiClient.mockImplementation((url: string, opts?: { method?: string }) => {
      if (opts?.method === 'DELETE' && url === '/api/v1/me/emails/2') {
        return jsonResponse(204, {});
      }
      return jsonResponse(200, { data: [PRIMARY, SECONDARY_UNVERIFIED] });
    });

    render(<EmailAddressesSettings />);
    await waitFor(() => expect(screen.getByTestId('email-remove-2')).toBeInTheDocument());

    fireEvent.click(screen.getByTestId('email-remove-2'));

    await waitFor(() => expect(addToast).toHaveBeenCalledWith(expect.stringContaining('removed'), 'success'));
    await waitFor(() => expect(screen.queryByTestId('email-row-2')).not.toBeInTheDocument());
  });
});
