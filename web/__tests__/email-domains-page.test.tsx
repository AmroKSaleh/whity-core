/**
 * WC-ac35b6cf — EmailDomainsPage tests.
 *
 * Mocks @/lib/api-client (the page imports the module-level `apiClient`
 * singleton, like SsoProvidersPage does, rather than useAuth().apiClient),
 * useAuth (for the client-side `admin` role gate), and useToast.
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

const mockApiClient = jest.fn();
jest.mock('@/lib/api-client', () => ({
  apiClient: (...args: unknown[]) => mockApiClient(...args),
}));

let mockUser: { role: string } | null = { role: 'admin' };
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ user: mockUser }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

import EmailDomainsPage from '@/app/(protected)/admin/settings/email-domains/page';

interface EmailDomain {
  id: number;
  tenant_id: number;
  domain: string;
  default_role_id: number;
  auto_provision: boolean;
  verified_at: string | null;
  is_verified: boolean;
  created_at: string;
  verification?: { record_name: string; record_type: string; record_value: string };
}

function jsonResponse(status: number, body: unknown) {
  return Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  });
}

const VERIFIED_DOMAIN: EmailDomain = {
  id: 1,
  tenant_id: 5,
  domain: 'example.com',
  default_role_id: 2,
  auto_provision: true,
  verified_at: '2026-01-01T00:00:00Z',
  is_verified: true,
  created_at: '2026-01-01T00:00:00Z',
};

const PENDING_DOMAIN: EmailDomain = {
  id: 2,
  tenant_id: 5,
  domain: 'pending.com',
  default_role_id: 2,
  auto_provision: true,
  verified_at: null,
  is_verified: false,
  created_at: '2026-01-02T00:00:00Z',
  verification: {
    record_name: '_whity-challenge.pending.com',
    record_type: 'TXT',
    record_value: 'whity-verify=abc123',
  },
};

const ROLES = [
  { id: 1, name: 'admin' },
  { id: 2, name: 'member' },
];

function mockRoutes(domains: EmailDomain[]) {
  mockApiClient.mockImplementation((url: string) => {
    if (url.startsWith('/api/v1/email-domains') === false && url.startsWith('/api/v1/roles')) {
      return jsonResponse(200, { data: ROLES });
    }
    if (url === '/api/v1/email-domains') {
      return jsonResponse(200, { data: domains });
    }
    if (url.startsWith('/api/v1/roles')) {
      return jsonResponse(200, { data: ROLES });
    }
    return jsonResponse(404, {});
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  mockUser = { role: 'admin' };
});

describe('EmailDomainsPage — role gate', () => {
  it('denies access for a non-admin role', async () => {
    mockUser = { role: 'member' };
    mockRoutes([]);

    render(<EmailDomainsPage />);

    expect(await screen.findByText('Access Denied')).toBeInTheDocument();
  });

  it('renders for an admin role', async () => {
    mockRoutes([VERIFIED_DOMAIN]);

    render(<EmailDomainsPage />);

    await waitFor(() => expect(screen.getByTestId('email-domain-1')).toBeInTheDocument());
  });
});

describe('EmailDomainsPage — list rendering', () => {
  it('shows a verified domain with the Verified badge and no challenge instructions', async () => {
    mockRoutes([VERIFIED_DOMAIN]);
    render(<EmailDomainsPage />);

    await waitFor(() => expect(screen.getByTestId('email-domain-status-1')).toHaveTextContent('Verified'));
    expect(screen.queryByTestId('email-domain-verify-1')).not.toBeInTheDocument();
  });

  it('shows a pending domain with challenge instructions and a Verify button', async () => {
    mockRoutes([PENDING_DOMAIN]);
    render(<EmailDomainsPage />);

    await waitFor(() =>
      expect(screen.getByTestId('email-domain-status-2')).toHaveTextContent('Pending verification')
    );
    expect(screen.getByText('_whity-challenge.pending.com')).toBeInTheDocument();
    expect(screen.getByText('whity-verify=abc123')).toBeInTheDocument();
    expect(screen.getByTestId('email-domain-verify-2')).toBeInTheDocument();
  });

  it('shows the empty state when no domains are registered', async () => {
    mockRoutes([]);
    render(<EmailDomainsPage />);

    await waitFor(() => expect(screen.getByText(/No email domains registered yet/i)).toBeInTheDocument());
  });
});

describe('EmailDomainsPage — add domain', () => {
  it('submits a new domain registration', async () => {
    mockRoutes([]);
    mockApiClient.mockImplementation((url: string, opts?: { method?: string }) => {
      if (opts?.method === 'POST' && url === '/api/v1/email-domains') {
        return jsonResponse(201, { data: PENDING_DOMAIN });
      }
      if (url === '/api/v1/email-domains') {
        return jsonResponse(200, { data: [] });
      }
      if (url.startsWith('/api/v1/roles')) {
        return jsonResponse(200, { data: ROLES });
      }
      return jsonResponse(404, {});
    });

    render(<EmailDomainsPage />);
    await waitFor(() => expect(screen.getByTestId('email-domains-add')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('email-domains-add'));

    await waitFor(() => expect(screen.getByTestId('email-domain-form')).toBeInTheDocument());
    fireEvent.change(screen.getByLabelText('Domain'), { target: { value: 'pending.com' } });
    fireEvent.click(screen.getByTestId('email-domain-save'));

    await waitFor(() => {
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/email-domains',
        expect.objectContaining({
          method: 'POST',
          body: JSON.stringify({ domain: 'pending.com', default_role_id: 1, auto_provision: true }),
        })
      );
    });
    await waitFor(() => expect(addToast).toHaveBeenCalledWith(expect.stringContaining('registered'), 'success'));
  });
});

describe('EmailDomainsPage — verify + delete', () => {
  it('calls the verify endpoint and shows a success toast on success', async () => {
    mockRoutes([PENDING_DOMAIN]);
    mockApiClient.mockImplementation((url: string, opts?: { method?: string }) => {
      if (opts?.method === 'POST' && url === '/api/v1/email-domains/2/verify') {
        return jsonResponse(200, { data: VERIFIED_DOMAIN });
      }
      if (url === '/api/v1/email-domains') {
        return jsonResponse(200, { data: [PENDING_DOMAIN] });
      }
      if (url.startsWith('/api/v1/roles')) {
        return jsonResponse(200, { data: ROLES });
      }
      return jsonResponse(404, {});
    });

    render(<EmailDomainsPage />);
    await waitFor(() => expect(screen.getByTestId('email-domain-verify-2')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('email-domain-verify-2'));

    await waitFor(() => expect(addToast).toHaveBeenCalledWith(expect.stringContaining('verified'), 'success'));
  });

  it('deletes a domain after confirming', async () => {
    mockRoutes([VERIFIED_DOMAIN]);
    mockApiClient.mockImplementation((url: string, opts?: { method?: string }) => {
      if (opts?.method === 'DELETE' && url === '/api/v1/email-domains/1') {
        return jsonResponse(204, {});
      }
      if (url === '/api/v1/email-domains') {
        return jsonResponse(200, { data: [VERIFIED_DOMAIN] });
      }
      if (url.startsWith('/api/v1/roles')) {
        return jsonResponse(200, { data: ROLES });
      }
      return jsonResponse(404, {});
    });

    render(<EmailDomainsPage />);
    await waitFor(() => expect(screen.getByTestId('email-domain-delete-1')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('email-domain-delete-1'));

    await waitFor(() => expect(screen.getByTestId('email-domain-confirm-delete-1')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('email-domain-confirm-delete-1'));

    await waitFor(() => expect(addToast).toHaveBeenCalledWith(expect.stringContaining('removed'), 'success'));
  });
});
