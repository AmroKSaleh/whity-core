/**
 * WC-password-reset-2fa-recovery — 2FA Auth Reset Approvals admin page tests.
 *
 * Mirrors password-reset-approvals-page.test.tsx; gated on
 * two_factor_recovery:approve, tenant-scoped (not system-tenant-restricted).
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

import TwoFactorRecoveryApprovalsPage from '@/app/(protected)/admin/approval-gating/two-factor-recovery/page';

const PENDING = [
  {
    id: 701,
    profile_id: 22,
    email: 'lockedout@acme.test',
    display_name: 'Locked Out User',
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
  grant('two_factor_recovery:approve');
  global.fetch = jest.fn();
});

describe('TwoFactorRecoveryApprovalsPage — access gate', () => {
  it('shows Access Denied for a caller lacking the permission', async () => {
    grant();
    render(<TwoFactorRecoveryApprovalsPage />);

    await waitFor(() =>
      expect(screen.getByTestId('two-factor-recovery-access-denied')).toBeInTheDocument()
    );
    expect(global.fetch).not.toHaveBeenCalled();
  });
});

describe('TwoFactorRecoveryApprovalsPage — list and actions', () => {
  it('lists pending requests for an eligible caller', async () => {
    mockFetchOnceJson({ data: PENDING });
    render(<TwoFactorRecoveryApprovalsPage />);

    await waitFor(() =>
      expect(screen.getByTestId('two-factor-recovery-row-701')).toBeInTheDocument()
    );
    expect(screen.getByText('lockedout@acme.test')).toBeInTheDocument();
    expect(global.fetch).toHaveBeenCalledWith(
      '/api/v1/2fa-recovery/pending',
      expect.objectContaining({ credentials: 'include' })
    );
  });

  it('renders the empty state when there are no pending requests', async () => {
    mockFetchOnceJson({ data: [] });
    render(<TwoFactorRecoveryApprovalsPage />);

    await waitFor(() => expect(screen.getByTestId('two-factor-recovery-empty')).toBeInTheDocument());
  });

  it('POSTs approve and removes the row on success', async () => {
    mockFetchOnceJson({ data: PENDING });
    mockFetchOnceJson({ data: { id: 701, status: 'approved' } });
    render(<TwoFactorRecoveryApprovalsPage />);

    await waitFor(() =>
      expect(screen.getByTestId('two-factor-recovery-approve-701')).toBeInTheDocument()
    );
    fireEvent.click(screen.getByTestId('two-factor-recovery-approve-701'));

    await waitFor(() =>
      expect(global.fetch).toHaveBeenCalledWith(
        '/api/v1/2fa-recovery/701/approve',
        expect.objectContaining({ method: 'POST' })
      )
    );
    await waitFor(() =>
      expect(screen.queryByTestId('two-factor-recovery-row-701')).not.toBeInTheDocument()
    );
  });

  it('POSTs reject to the reject endpoint', async () => {
    mockFetchOnceJson({ data: PENDING });
    mockFetchOnceJson({ data: { id: 701, status: 'rejected' } });
    render(<TwoFactorRecoveryApprovalsPage />);

    await waitFor(() =>
      expect(screen.getByTestId('two-factor-recovery-reject-701')).toBeInTheDocument()
    );
    fireEvent.click(screen.getByTestId('two-factor-recovery-reject-701'));

    await waitFor(() =>
      expect(global.fetch).toHaveBeenCalledWith(
        '/api/v1/2fa-recovery/701/reject',
        expect.objectContaining({ method: 'POST' })
      )
    );
  });

  it('surfaces a load error with a retry affordance', async () => {
    mockFetchOnceJson({ error: 'nope' }, false, 403);
    render(<TwoFactorRecoveryApprovalsPage />);

    await waitFor(() =>
      expect(screen.getByTestId('two-factor-recovery-load-error')).toBeInTheDocument()
    );
  });
});

describe('TwoFactorRecoveryApprovalsPage — approval-gating tab bar', () => {
  it('renders the shared tab bar with the 2FA tab active', async () => {
    mockFetchOnceJson({ data: [] });
    render(<TwoFactorRecoveryApprovalsPage />);

    await waitFor(() => expect(screen.getByTestId('approval-gating-tabs')).toBeInTheDocument());
    expect(screen.getByTestId('approval-gating-tab-two-factor-recovery')).toHaveAttribute(
      'aria-current',
      'page'
    );
  });
});
