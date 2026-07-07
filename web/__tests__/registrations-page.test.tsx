/**
 * WC-235 — Pending Registrations admin page tests.
 *
 * Verifies the system-tenant + permission gate and the approve/reject flow
 * without a server (global fetch, useAuth, useCapabilities and useToast are
 * mocked). Mirrors the branding-settings test's mocking style.
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

// ---------------------------------------------------------------------------
// Module mocks
// ---------------------------------------------------------------------------

// Mutable tenant id so a single file can exercise both the system tenant (0)
// and a regular tenant (1). `mock`-prefixed so the jest factory may close over
// it. The arrow reads it at call time, so tests set it before render.
let mockTenantId = 0;
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({
    user: { id: 1, email: 'sys@example.com', role: 'admin', tenant_id: mockTenantId },
  }),
}));

const hasPermission = jest.fn<boolean, [string]>();
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ hasPermission, loading: false, permissions: [] }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

import PendingRegistrationsPage from '@/app/(protected)/admin/registrations/page';

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

const PENDING = [
  {
    membership_id: 1002,
    tenant_id: 2,
    tenant_name: 'Acme Inc',
    tenant_slug: 'acme-inc',
    profile_id: 12,
    display_name: 'Acme Owner',
    owner_email: 'owner@acme.test',
    created_at: '2026-07-01T00:00:00Z',
  },
];

function grant(...perms: string[]) {
  hasPermission.mockImplementation((slug: string) => perms.includes(slug));
}

function mockFetchOnceJson(data: unknown, ok = true, status = 200) {
  (global.fetch as jest.Mock).mockResolvedValueOnce({
    ok,
    status,
    json: async () => data,
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  mockTenantId = 0;
  grant('registrations:approve');
  global.fetch = jest.fn();
});

// ---------------------------------------------------------------------------
// Gate
// ---------------------------------------------------------------------------

describe('PendingRegistrationsPage — access gate', () => {
  it('shows Access Denied for a non-system tenant even with the permission', async () => {
    mockTenantId = 1; // regular tenant admin
    render(<PendingRegistrationsPage />);

    await waitFor(() =>
      expect(screen.getByTestId('registrations-access-denied')).toBeInTheDocument()
    );
    // Must never fetch the pending list when ineligible.
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('shows Access Denied for a system-tenant caller lacking the permission', async () => {
    mockTenantId = 0;
    grant(); // no permissions
    render(<PendingRegistrationsPage />);

    await waitFor(() =>
      expect(screen.getByTestId('registrations-access-denied')).toBeInTheDocument()
    );
    expect(global.fetch).not.toHaveBeenCalled();
  });
});

// ---------------------------------------------------------------------------
// List + actions
// ---------------------------------------------------------------------------

describe('PendingRegistrationsPage — list and actions', () => {
  it('lists pending registrations for an eligible system-tenant admin', async () => {
    mockFetchOnceJson({ data: PENDING });
    render(<PendingRegistrationsPage />);

    await waitFor(() =>
      expect(screen.getByTestId('registration-row-1002')).toBeInTheDocument()
    );
    expect(screen.getByText('Acme Inc')).toBeInTheDocument();
    expect(screen.getByText('owner@acme.test')).toBeInTheDocument();
    expect(global.fetch).toHaveBeenCalledWith(
      '/api/v1/registrations/pending',
      expect.objectContaining({ credentials: 'include' })
    );
  });

  it('renders the empty state when there are no pending registrations', async () => {
    mockFetchOnceJson({ data: [] });
    render(<PendingRegistrationsPage />);

    await waitFor(() => expect(screen.getByTestId('registrations-empty')).toBeInTheDocument());
  });

  it('POSTs approve and removes the row on success', async () => {
    mockFetchOnceJson({ data: PENDING }); // initial load
    mockFetchOnceJson({ data: { membership_id: 1002, status: 'active' } }); // approve
    render(<PendingRegistrationsPage />);

    await waitFor(() =>
      expect(screen.getByTestId('registration-approve-1002')).toBeInTheDocument()
    );
    fireEvent.click(screen.getByTestId('registration-approve-1002'));

    await waitFor(() =>
      expect(global.fetch).toHaveBeenCalledWith(
        '/api/v1/registrations/1002/approve',
        expect.objectContaining({ method: 'POST' })
      )
    );
    await waitFor(() =>
      expect(screen.queryByTestId('registration-row-1002')).not.toBeInTheDocument()
    );
    expect(addToast).toHaveBeenCalledWith(expect.stringContaining('Approved'), 'success');
  });

  it('POSTs reject to the reject endpoint', async () => {
    mockFetchOnceJson({ data: PENDING });
    mockFetchOnceJson({ data: { membership_id: 1002, status: 'suspended' } });
    render(<PendingRegistrationsPage />);

    await waitFor(() =>
      expect(screen.getByTestId('registration-reject-1002')).toBeInTheDocument()
    );
    fireEvent.click(screen.getByTestId('registration-reject-1002'));

    await waitFor(() =>
      expect(global.fetch).toHaveBeenCalledWith(
        '/api/v1/registrations/1002/reject',
        expect.objectContaining({ method: 'POST' })
      )
    );
  });

  it('surfaces a load error with a retry affordance', async () => {
    mockFetchOnceJson({ error: 'nope' }, false, 403);
    render(<PendingRegistrationsPage />);

    await waitFor(() =>
      expect(screen.getByTestId('registrations-load-error')).toBeInTheDocument()
    );
  });
});
