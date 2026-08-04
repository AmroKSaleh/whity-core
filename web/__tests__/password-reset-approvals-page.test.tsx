/**
 * WC-password-reset-2fa-recovery — Password Reset Approvals admin page tests.
 *
 * Mirrors registrations-page.test.tsx's mocking style, but this queue is
 * tenant-scoped (NOT system-tenant-restricted) — gated only on
 * password_resets:approve, unlike the Signup tab.
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

const hasPermission = jest.fn<boolean, [string]>();
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ hasPermission, loading: false, permissions: [] }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

import PasswordResetApprovalsPage from '@/app/(protected)/admin/approval-gating/password-resets/page';

const PENDING = [
  {
    id: 501,
    profile_id: 12,
    email: 'requester@acme.test',
    display_name: 'Requester',
    created_at: '2026-08-01T00:00:00Z',
  },
];

function grant(...perms: string[]) {
  hasPermission.mockImplementation((slug: string) => perms.includes(slug));
}

function mockFetchOnceJson(data: unknown, ok = true, status = 200) {
  (global.fetch as jest.Mock).mockResolvedValueOnce({ ok, status, json: async () => data });
}

beforeEach(() => {
  jest.clearAllMocks();
  grant('password_resets:approve');
  global.fetch = jest.fn();
});

describe('PasswordResetApprovalsPage — access gate', () => {
  it('shows Access Denied for a caller lacking the permission', async () => {
    grant(); // no permissions
    render(<PasswordResetApprovalsPage />);

    await waitFor(() =>
      expect(screen.getByTestId('password-resets-access-denied')).toBeInTheDocument()
    );
    expect(global.fetch).not.toHaveBeenCalled();
  });
});

describe('PasswordResetApprovalsPage — list and actions', () => {
  it('lists pending requests for an eligible caller (no system-tenant check)', async () => {
    mockFetchOnceJson({ data: PENDING });
    render(<PasswordResetApprovalsPage />);

    await waitFor(() => expect(screen.getByTestId('password-reset-row-501')).toBeInTheDocument());
    expect(screen.getByText('requester@acme.test')).toBeInTheDocument();
    expect(global.fetch).toHaveBeenCalledWith(
      '/api/v1/password-resets/pending',
      expect.objectContaining({ credentials: 'include' })
    );
  });

  it('renders the empty state when there are no pending requests', async () => {
    mockFetchOnceJson({ data: [] });
    render(<PasswordResetApprovalsPage />);

    await waitFor(() => expect(screen.getByTestId('password-resets-empty')).toBeInTheDocument());
  });

  it('POSTs approve and removes the row on success', async () => {
    mockFetchOnceJson({ data: PENDING });
    mockFetchOnceJson({ data: { id: 501, status: 'approved' } });
    render(<PasswordResetApprovalsPage />);

    await waitFor(() => expect(screen.getByTestId('password-reset-approve-501')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('password-reset-approve-501'));

    await waitFor(() =>
      expect(global.fetch).toHaveBeenCalledWith(
        '/api/v1/password-resets/501/approve',
        expect.objectContaining({ method: 'POST' })
      )
    );
    await waitFor(() =>
      expect(screen.queryByTestId('password-reset-row-501')).not.toBeInTheDocument()
    );
  });

  it('POSTs reject to the reject endpoint', async () => {
    mockFetchOnceJson({ data: PENDING });
    mockFetchOnceJson({ data: { id: 501, status: 'rejected' } });
    render(<PasswordResetApprovalsPage />);

    await waitFor(() => expect(screen.getByTestId('password-reset-reject-501')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('password-reset-reject-501'));

    await waitFor(() =>
      expect(global.fetch).toHaveBeenCalledWith(
        '/api/v1/password-resets/501/reject',
        expect.objectContaining({ method: 'POST' })
      )
    );
  });

  it('surfaces a load error with a retry affordance', async () => {
    mockFetchOnceJson({ error: 'nope' }, false, 403);
    render(<PasswordResetApprovalsPage />);

    await waitFor(() => expect(screen.getByTestId('password-resets-load-error')).toBeInTheDocument());
  });
});

describe('PasswordResetApprovalsPage — approval-gating tab bar', () => {
  it('renders the shared tab bar with the password-resets tab active', async () => {
    mockFetchOnceJson({ data: [] });
    render(<PasswordResetApprovalsPage />);

    await waitFor(() => expect(screen.getByTestId('approval-gating-tabs')).toBeInTheDocument());
    expect(screen.getByTestId('approval-gating-tab-password-resets')).toHaveAttribute(
      'aria-current',
      'page'
    );
  });
});
