/**
 * WC-222: the Edit/Delete role modals show a FRIENDLY toast (not a generic
 * error / raw console noise) when the API returns 404 — the by-design response
 * for a global base role that the caller's tenant may not manage (WC-110). This
 * is a safety net behind the per-row UI gate.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { EditRoleModal } from '@/app/(protected)/admin/roles/edit-modal';
import { DeleteRoleModal } from '@/app/(protected)/admin/roles/delete-modal';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import type { Role } from '@/app/(protected)/admin/roles/types';

jest.mock('@/lib/auth-context', () => ({
  useAuth: jest.fn(),
}));
jest.mock('@/lib/toast-context', () => ({
  useToast: jest.fn(),
}));

const mockUseAuth = useAuth as jest.MockedFunction<typeof useAuth>;
const mockUseToast = useToast as jest.MockedFunction<typeof useToast>;

const FRIENDLY_404 =
  "This role can't be modified by your tenant — global base roles are managed by the system tenant.";

const ROLE: Role = {
  id: 1,
  name: 'admin',
  description: 'Global base role',
  createdAt: '2026-01-01',
  permissionCount: 3,
  manageable: false,
};

const addToast = jest.fn();

function makeApiClient(
  handler: (url: string, init?: RequestInit) => Promise<unknown>
): jest.Mock {
  const apiClient = jest.fn(handler);
  mockUseAuth.mockReturnValue({ apiClient } as unknown as ReturnType<
    typeof useAuth
  >);
  return apiClient;
}

beforeEach(() => {
  jest.clearAllMocks();
  mockUseToast.mockReturnValue({ addToast } as unknown as ReturnType<
    typeof useToast
  >);
});

describe('EditRoleModal 404 safety net (WC-222)', () => {
  it('shows the friendly toast when PATCH returns 404', async () => {
    const user = userEvent.setup();
    // GET permissions, GET role detail succeed; PATCH 404s.
    makeApiClient(async (url, init) => {
      if (init?.method === 'PATCH') {
        return { ok: false, status: 404, json: async () => ({}) };
      }
      if (url.endsWith('/permissions')) {
        return { ok: true, json: async () => ({ data: [] }) };
      }
      // role detail
      return {
        ok: true,
        json: async () => ({
          data: { ...ROLE, permissions: [] },
        }),
      };
    });

    render(
      <EditRoleModal
        isOpen
        onOpenChange={jest.fn()}
        role={ROLE}
        onSuccess={jest.fn()}
      />
    );

    // Wait for the form (role details loaded) and submit it.
    const save = await screen.findByRole('button', { name: /save changes/i });
    await user.click(save);

    await waitFor(() =>
      expect(addToast).toHaveBeenCalledWith(FRIENDLY_404, 'error')
    );
    // The generic "Failed to update role" message must NOT be used.
    expect(addToast).not.toHaveBeenCalledWith(
      expect.stringMatching(/failed to update/i),
      'error'
    );
  });
});

describe('DeleteRoleModal 404 safety net (WC-222)', () => {
  it('shows the friendly toast when DELETE returns 404', async () => {
    const user = userEvent.setup();
    makeApiClient(async () => ({
      ok: false,
      status: 404,
      json: async () => ({}),
    }));

    render(
      <DeleteRoleModal
        isOpen
        onOpenChange={jest.fn()}
        role={ROLE}
        onSuccess={jest.fn()}
      />
    );

    const del = await screen.findByRole('button', { name: /delete role/i });
    await user.click(del);

    await waitFor(() =>
      expect(addToast).toHaveBeenCalledWith(FRIENDLY_404, 'error')
    );
    expect(addToast).not.toHaveBeenCalledWith(
      expect.stringMatching(/failed to delete/i),
      'error'
    );
  });
});
