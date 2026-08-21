/**
 * WC-222 (post Path-B extraction): a PATCH/DELETE that returns 404 — the
 * by-design response for a global base role the caller's tenant may not manage
 * (WC-110) — must NOT surface as a raw error. The behaviour is now split across
 * the extraction seam:
 *
 *   1. web's `createRolesAdapter` maps a 404 → the sentinel 'not-manageable'
 *      (this file's adapter unit tests), and
 *   2. the extracted `RolesScreen`/modals map 'not-manageable' → a FRIENDLY
 *      toast (this file's end-to-end test), never the generic failure copy.
 *
 * Together they preserve the safety net behind the per-row UI gate.
 */

import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { RolesScreen } from '@amroksaleh/features/roles';
import type { Role, RolesAdapter, Transport } from '@amroksaleh/features/roles';
import { createRolesAdapter } from '@/lib/roles-adapter';

const FRIENDLY_404 =
  "This role can't be modified by your tenant — global base roles are managed by the system tenant.";

const ROLE_INPUT = { name: 'admin', description: 'Global base role', permissions: [] };

/** A transport that always answers with one fixed `{ status, body }`. */
function transportReturning(status: number, body: unknown = {}): Transport {
  return { request: jest.fn().mockResolvedValue({ status, body }) };
}

describe('web roles adapter — 404 → not-manageable (WC-222)', () => {
  it('maps a 404 PATCH to the "not-manageable" sentinel', async () => {
    const adapter = createRolesAdapter(transportReturning(404));
    await expect(adapter.updateRole(1, ROLE_INPUT)).resolves.toBe('not-manageable');
  });

  it('maps a 404 DELETE to the "not-manageable" sentinel', async () => {
    const adapter = createRolesAdapter(transportReturning(404));
    await expect(adapter.deleteRole(1)).resolves.toBe('not-manageable');
  });

  it('resolves "ok" on a successful PATCH', async () => {
    const adapter = createRolesAdapter(transportReturning(200, { data: {} }));
    await expect(adapter.updateRole(1, ROLE_INPUT)).resolves.toBe('ok');
  });

  it('throws (does NOT swallow) a non-404 failure, surfacing the server message', async () => {
    const adapter = createRolesAdapter(transportReturning(500, { message: 'boom' }));
    await expect(adapter.updateRole(1, ROLE_INPUT)).rejects.toThrow('boom');
  });
});

// ---------------------------------------------------------------------------

const MANAGEABLE_ROLE: Role = {
  id: 10,
  name: 'TenantCustom',
  description: 'A tenant-owned role',
  createdAt: '2026-01-01',
  permissionCount: 2,
  manageable: true,
};

const t = (_key: string, fallback?: string) => fallback ?? _key;
const can = () => true;

function fakeAdapter(over: Partial<RolesAdapter> = {}): RolesAdapter {
  return {
    listRoles: jest.fn().mockResolvedValue([MANAGEABLE_ROLE]),
    getRole: jest.fn().mockResolvedValue({ ...MANAGEABLE_ROLE, permissions: [] }),
    getRolePermissions: jest.fn().mockResolvedValue([]),
    getRoleAssignments: jest.fn().mockResolvedValue({ assignments: [], total: 0 }),
    getRoleActivity: jest.fn().mockResolvedValue([]),
    listPermissions: jest.fn().mockResolvedValue([]),
    createRole: jest.fn().mockResolvedValue(undefined),
    updateRole: jest.fn().mockResolvedValue('ok'),
    deleteRole: jest.fn().mockResolvedValue('ok'),
    getCapabilities: jest.fn().mockResolvedValue([]),
    ...over,
  };
}

describe('RolesScreen surfaces the friendly toast on not-manageable (WC-222)', () => {
  it('shows the friendly message (not the generic failure) when deleteRole reports not-manageable', async () => {
    const user = userEvent.setup();
    const onNotify = jest.fn();
    const adapter = fakeAdapter({ deleteRole: jest.fn().mockResolvedValue('not-manageable') });

    render(<RolesScreen adapter={adapter} can={can} t={t} onNotify={onNotify} />);
    await screen.findByText('TenantCustom');

    // Open the row menu and choose Delete (enabled — the role is manageable).
    const row = screen.getByText('TenantCustom').closest('tr');
    await user.click(within(row as HTMLElement).getByRole('button'));
    const menu = await screen.findByRole('menu');
    await user.click(within(menu).getByText('Delete'));

    // Confirm in the delete dialog.
    const confirm = await screen.findByRole('button', { name: 'Delete Role' });
    await user.click(confirm);

    await waitFor(() => expect(onNotify).toHaveBeenCalledWith(FRIENDLY_404, 'error'));
    expect(onNotify).not.toHaveBeenCalledWith(
      expect.stringMatching(/failed to delete/i),
      'error'
    );
  });
});
