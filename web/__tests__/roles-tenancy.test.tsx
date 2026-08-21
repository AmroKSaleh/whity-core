/**
 * #886 / #888 — a role's TENANCY, in the two places it goes wrong.
 *
 * #886: the roles LIST returns a tenant's own roles PLUS the global base roles
 * every tenant inherits, and rendered both identically. An operator edited what
 * looked like an ordinary role and changed it for the entire deployment with
 * nothing having said so — which is how "a role edited in a tenant affects the
 * same named role in another tenant" was reported as a cross-tenant write bug.
 * It is not one: there is genuinely one row.
 *
 * The distinction CANNOT be carried by `manageable`. For a tenant-0 operator
 * every role is manageable — and that operator is precisely the only one who can
 * perform the deployment-wide edit. So these assertions all turn on a separate
 * server-supplied `global` flag, and several of them fail against the previous
 * `!manageable` inference.
 *
 * #888: `POST /api/roles` stamped the caller's own tenant unconditionally, so an
 * operator administering from tenant 0 could create neither a role FOR a tenant
 * nor a genuinely shared one. The wire grammar for that fix is asserted here at
 * the adapter seam, where both clients share it.
 */

import React from 'react';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// Radix's Select is not driveable in jsdom (`target.hasPointerCapture is not a
// function`), so it is swapped for a native <select> that keeps the same
// value/onValueChange contract - the identical shim, for the identical reason,
// as `user-memberships-modal.test.tsx` and the OU picker tests. The assertions
// below are about which scopes are OFFERED and what the choice sends, neither of
// which is about Radix's popover mechanics.
const SelectContext = React.createContext<{
  registerOption: (value: string, label: string) => void;
} | null>(null);

function MockSelect({
  children,
  onValueChange,
  value,
}: React.PropsWithChildren<{ onValueChange?: (v: string) => void; value?: string }>) {
  const [options, setOptions] = React.useState<Array<{ value: string; label: string }>>([]);
  const registerOption = React.useCallback((v: string, label: string) => {
    setOptions((prev) => (prev.some((o) => o.value === v) ? prev : [...prev, { value: v, label }]));
  }, []);

  return (
    <SelectContext.Provider value={{ registerOption }}>
      <div style={{ display: 'none' }}>{children}</div>
      <select
        data-testid="scope-select"
        value={value ?? ''}
        onChange={(e) => onValueChange?.(e.target.value)}
      >
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </SelectContext.Provider>
  );
}

function MockSelectItem({ children, value }: React.PropsWithChildren<{ value: string }>) {
  const ctx = React.useContext(SelectContext);
  React.useEffect(() => {
    ctx?.registerOption(value, String(children));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value, children]);
  return null;
}

jest.mock('@amroksaleh/ui/select', () => ({
  Select: MockSelect,
  SelectTrigger: ({ children }: React.PropsWithChildren) => <>{children}</>,
  SelectValue: () => null,
  SelectContent: ({ children }: React.PropsWithChildren) => <>{children}</>,
  SelectItem: MockSelectItem,
}));

import { RolesScreen, RoleRecordScreen } from '@amroksaleh/features/roles';
import type {
  Role,
  RoleWithPermissions,
  RolesAdapter,
  Transport,
} from '@amroksaleh/features/roles';
import { createRolesAdapter } from '@/lib/roles-adapter';

const GLOBAL_BADGE = 'Global';
const EDIT_WARNING =
  'This is a global base role: one role shared by every tenant on this deployment. Saving changes it for all of them, including their existing users.';
const DELETE_WARNING =
  'This is a global base role: one role shared by every tenant on this deployment. Deleting it removes it from all of them.';

/** A tenant's own role: not global, and writable by its owner. */
const OWNED_ROLE: Role = {
  id: 10,
  name: 'Ward Supervisor',
  description: 'Runs a ward',
  createdAt: '2026-01-01',
  permissionCount: 2,
  manageable: true,
  global: false,
};

/**
 * A global base role AS A TENANT-0 OPERATOR SEES IT: global AND manageable.
 * This combination is the whole defect — under the old inference it rendered
 * exactly like `OWNED_ROLE`.
 */
const GLOBAL_ROLE_FOR_OPERATOR: Role = {
  id: 1,
  name: 'admin',
  description: 'Global base role',
  createdAt: '2026-01-01',
  permissionCount: 5,
  manageable: true,
  global: true,
};

/** The same row as an ordinary tenant sees it: global and NOT manageable. */
const GLOBAL_ROLE_FOR_TENANT: Role = { ...GLOBAL_ROLE_FOR_OPERATOR, manageable: false };

/** English-fallback translator: returns the caller-supplied source string. */
const t = (key: string, fallback?: string) => fallback ?? key;
/** The caller holds every capability, so only tenancy differs between cases. */
const can = () => true;

function fakeAdapter(over: Partial<RolesAdapter> = {}): RolesAdapter {
  return {
    listRoles: jest.fn().mockResolvedValue([]),
    getRole: jest.fn().mockResolvedValue({ ...OWNED_ROLE, permissions: [] }),
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

/** Open a row's actions dropdown and return the menu. */
async function openRowMenu(user: ReturnType<typeof userEvent.setup>, roleName: string) {
  const row = screen.getByText(roleName).closest('tr');
  expect(row).not.toBeNull();
  // The row has two buttons since #882 (the name, and the actions trigger), so
  // the trigger is named rather than "the button in this row".
  await user.click(within(row as HTMLElement).getByRole('button', { name: 'Actions' }));
  return await screen.findByRole('menu');
}

// ---------------------------------------------------------------------------
// #886 — the LIST says which roles are shared
// ---------------------------------------------------------------------------

describe('RolesScreen marks GLOBAL rows in the list (#886)', () => {
  it('badges a global row and leaves a tenant-owned row unmarked', async () => {
    const adapter = fakeAdapter({
      listRoles: jest.fn().mockResolvedValue([GLOBAL_ROLE_FOR_TENANT, OWNED_ROLE]),
    });

    render(<RolesScreen adapter={adapter} can={can} t={t} />);

    await screen.findByText(GLOBAL_ROLE_FOR_TENANT.name);
    const globalRow = screen.getByText(GLOBAL_ROLE_FOR_TENANT.name).closest('tr');
    const ownedRow = screen.getByText(OWNED_ROLE.name).closest('tr');

    expect(within(globalRow as HTMLElement).getByTestId('role-row-global-badge')).toHaveTextContent(
      GLOBAL_BADGE
    );
    expect(within(ownedRow as HTMLElement).queryByTestId('role-row-global-badge')).toBeNull();
  });

  /**
   * The regression that matters. `manageable` is true for BOTH rows here, so a
   * marker derived from it would mark neither — and the caller it fails for is
   * the only one who can actually make the deployment-wide change.
   */
  it('still badges the global row for a tenant-0 operator, for whom every role is manageable', async () => {
    const adapter = fakeAdapter({
      listRoles: jest.fn().mockResolvedValue([GLOBAL_ROLE_FOR_OPERATOR, OWNED_ROLE]),
    });

    render(<RolesScreen adapter={adapter} can={can} t={t} />);

    await screen.findByText(GLOBAL_ROLE_FOR_OPERATOR.name);
    const globalRow = screen.getByText(GLOBAL_ROLE_FOR_OPERATOR.name).closest('tr');
    const ownedRow = screen.getByText(OWNED_ROLE.name).closest('tr');

    expect(within(globalRow as HTMLElement).getByTestId('role-row-global-badge')).toBeInTheDocument();
    expect(within(ownedRow as HTMLElement).queryByTestId('role-row-global-badge')).toBeNull();
  });

  it('keeps Edit/Delete gated on manageability, not on globality', async () => {
    const user = userEvent.setup();
    const adapter = fakeAdapter({
      listRoles: jest.fn().mockResolvedValue([GLOBAL_ROLE_FOR_OPERATOR]),
    });

    render(<RolesScreen adapter={adapter} can={can} t={t} />);
    await screen.findByText(GLOBAL_ROLE_FOR_OPERATOR.name);

    const menu = await openRowMenu(user, GLOBAL_ROLE_FOR_OPERATOR.name);
    // Global but manageable: the operator MAY edit it — the badge and warning
    // inform the decision, they do not take it away.
    expect(within(menu).getByRole('menuitem', { name: 'Edit' })).not.toHaveAttribute(
      'aria-disabled',
      'true'
    );
  });
});

// ---------------------------------------------------------------------------
// #886 — the blast radius, said out loud before the edit
// ---------------------------------------------------------------------------

describe('The deployment-wide edit announces itself (#886)', () => {
  it('warns in the edit modal when a global role is opened by a caller who may write it', async () => {
    const user = userEvent.setup();
    const adapter = fakeAdapter({
      listRoles: jest.fn().mockResolvedValue([GLOBAL_ROLE_FOR_OPERATOR]),
      getRole: jest.fn().mockResolvedValue({ ...GLOBAL_ROLE_FOR_OPERATOR, permissions: [] }),
    });

    // No `onOpenRecord`: without the record-page seam, Edit opens the MODAL.
    render(<RolesScreen adapter={adapter} can={can} t={t} />);
    await screen.findByText(GLOBAL_ROLE_FOR_OPERATOR.name);

    const menu = await openRowMenu(user, GLOBAL_ROLE_FOR_OPERATOR.name);
    await user.click(within(menu).getByRole('menuitem', { name: 'Edit' }));

    const warning = await screen.findByTestId('role-edit-global-warning');
    expect(warning).toHaveTextContent(EDIT_WARNING);
  });

  it('warns in the delete modal too, since that half is irreversible', async () => {
    const user = userEvent.setup();
    const adapter = fakeAdapter({
      listRoles: jest.fn().mockResolvedValue([GLOBAL_ROLE_FOR_OPERATOR]),
    });

    render(<RolesScreen adapter={adapter} can={can} t={t} />);
    await screen.findByText(GLOBAL_ROLE_FOR_OPERATOR.name);

    const menu = await openRowMenu(user, GLOBAL_ROLE_FOR_OPERATOR.name);
    await user.click(within(menu).getByRole('menuitem', { name: 'Delete' }));

    const warning = await screen.findByTestId('role-delete-global-warning');
    expect(warning).toHaveTextContent(DELETE_WARNING);
  });

  it('does not warn for an ordinary tenant-owned role', async () => {
    const user = userEvent.setup();
    const adapter = fakeAdapter({ listRoles: jest.fn().mockResolvedValue([OWNED_ROLE]) });

    render(<RolesScreen adapter={adapter} can={can} t={t} />);
    await screen.findByText(OWNED_ROLE.name);

    const menu = await openRowMenu(user, OWNED_ROLE.name);
    await user.click(within(menu).getByRole('menuitem', { name: 'Edit' }));

    await screen.findByRole('dialog');
    expect(screen.queryByTestId('role-edit-global-warning')).toBeNull();
  });
});

// ---------------------------------------------------------------------------
// #886 — the record page reads `global`, not `!manageable`
// ---------------------------------------------------------------------------

describe('RoleRecordScreen reports scope from the server flag (#886)', () => {
  const GLOBAL_DETAIL: RoleWithPermissions = {
    ...GLOBAL_ROLE_FOR_OPERATOR,
    permissions: [],
  };

  it('badges a global role AND warns, even though the operator may edit it', async () => {
    const adapter = fakeAdapter({ getRole: jest.fn().mockResolvedValue(GLOBAL_DETAIL) });

    render(
      <RoleRecordScreen adapter={adapter} roleId={1} can={can} t={t} onBack={() => undefined} />
    );

    expect(await screen.findByTestId('role-record-global-badge')).toBeInTheDocument();
    expect(await screen.findByTestId('role-record-global-warning')).toHaveTextContent(EDIT_WARNING);
    // Manageable, so the page is NOT read-only — the warning is the whole point:
    // an editable form on a role that reaches every tenant.
    expect(screen.queryByTestId('role-record-readonly-notice')).toBeNull();
  });

  it('shows neither badge nor warning for a tenant-owned role', async () => {
    const adapter = fakeAdapter({
      getRole: jest.fn().mockResolvedValue({ ...OWNED_ROLE, permissions: [] }),
    });

    render(
      <RoleRecordScreen adapter={adapter} roleId={10} can={can} t={t} onBack={() => undefined} />
    );

    await screen.findByTestId('role-record');
    expect(screen.queryByTestId('role-record-global-badge')).toBeNull();
    expect(screen.queryByTestId('role-record-global-warning')).toBeNull();
    expect(screen.getByText("Your tenant's role")).toBeInTheDocument();
  });

  /**
   * A global role a TENANT opened: still badged, but the warning is suppressed —
   * the read-only notice already explains the situation, and two overlapping
   * notices is one too many.
   */
  it('badges but does not warn when the caller cannot write the global role anyway', async () => {
    const adapter = fakeAdapter({
      getRole: jest.fn().mockResolvedValue({ ...GLOBAL_ROLE_FOR_TENANT, permissions: [] }),
    });

    render(
      <RoleRecordScreen adapter={adapter} roleId={1} can={can} t={t} onBack={() => undefined} />
    );

    expect(await screen.findByTestId('role-record-global-badge')).toBeInTheDocument();
    expect(screen.queryByTestId('role-record-global-warning')).toBeNull();
    expect(screen.getByTestId('role-record-readonly-notice')).toBeInTheDocument();
  });
});

// ---------------------------------------------------------------------------
// #888 — the create scope picker, and the wire grammar behind it
// ---------------------------------------------------------------------------

describe('Create-role scope picker (#888)', () => {
  const TENANTS = [
    { id: 7, name: 'Northside Clinic' },
    { id: 9, name: 'Southside Clinic' },
  ];

  /** Open the Create modal, returning the adapter the screen was given. */
  async function openCreateModal(
    props: Partial<React.ComponentProps<typeof RolesScreen>> = {}
  ): Promise<{ user: ReturnType<typeof userEvent.setup>; adapter: RolesAdapter }> {
    const user = userEvent.setup();
    const adapter = (props.adapter as RolesAdapter | undefined) ?? fakeAdapter();
    render(<RolesScreen can={can} t={t} {...props} adapter={adapter} />);
    await user.click(screen.getByRole('button', { name: /Create Role/ }));
    await screen.findByRole('dialog');
    return { user, adapter };
  }

  /**
   * The picker, once its tenants have arrived. Waiting for the OPTIONS rather
   * than the element matters: a change event naming an option that has not been
   * rendered yet is silently dropped by jsdom.
   */
  async function scopeSelect(optionCount: number): Promise<HTMLSelectElement> {
    return await waitFor(() => {
      const select = screen.getByTestId('scope-select') as HTMLSelectElement;
      expect(select.querySelectorAll('option')).toHaveLength(optionCount);
      return select;
    });
  }

  /** Fill the two required fields and submit. */
  async function submitNamed(user: ReturnType<typeof userEvent.setup>): Promise<void> {
    await user.type(screen.getByPlaceholderText('e.g., Editor'), 'Nurse');
    await user.type(screen.getByPlaceholderText('Role description'), 'Ward nurse');
    await user.click(screen.getByRole('button', { name: 'Create Role' }));
  }

  it('shows NO picker when the host supplies no scope seam', async () => {
    await openCreateModal();
    expect(screen.queryByTestId('scope-select')).toBeNull();
  });

  it('offers own / global / every tenant when the host supplies the seam', async () => {
    const loadTenants = jest.fn().mockResolvedValue(TENANTS);
    await openCreateModal({ scope: { loadTenants } });

    await waitFor(() => expect(loadTenants).toHaveBeenCalled());
    const select = await scopeSelect(4);

    expect(Array.from(select.querySelectorAll('option')).map((o) => o.textContent)).toEqual([
      'My own tenant',
      'Global — shared by every tenant',
      'Northside Clinic',
      'Southside Clinic',
    ]);
    // The default is the caller's own tenant, so a create nobody has thought
    // about behaves exactly as it did before #888.
    expect(select.value).toBe('own');
  });

  it('sends no scope at all when the default is kept', async () => {
    const adapter = fakeAdapter();
    const { user } = await openCreateModal({
      adapter,
      scope: { loadTenants: jest.fn().mockResolvedValue(TENANTS) },
    });

    await scopeSelect(4);
    await submitNamed(user);

    await waitFor(() => expect(adapter.createRole).toHaveBeenCalled());
    const [input] = (adapter.createRole as jest.Mock).mock.calls[0];
    expect(input).not.toHaveProperty('scope');
  });

  it('carries the chosen tenant through to the adapter', async () => {
    const adapter = fakeAdapter();
    const { user } = await openCreateModal({
      adapter,
      scope: { loadTenants: jest.fn().mockResolvedValue(TENANTS) },
    });

    fireEvent.change(await scopeSelect(4), { target: { value: '7' } });
    await submitNamed(user);

    await waitFor(() => expect(adapter.createRole).toHaveBeenCalled());
    const [input] = (adapter.createRole as jest.Mock).mock.calls[0];
    expect(input.scope).toBe(7);
  });

  it('carries the global choice through as the closed value, not a tenant id', async () => {
    const adapter = fakeAdapter();
    const { user } = await openCreateModal({
      adapter,
      scope: { loadTenants: jest.fn().mockResolvedValue(TENANTS) },
    });

    fireEvent.change(await scopeSelect(4), { target: { value: 'global' } });
    await submitNamed(user);

    await waitFor(() => expect(adapter.createRole).toHaveBeenCalled());
    const [input] = (adapter.createRole as jest.Mock).mock.calls[0];
    expect(input.scope).toBe('global');
  });

  it('explains what a global role is before one is created', async () => {
    await openCreateModal({ scope: { loadTenants: jest.fn().mockResolvedValue(TENANTS) } });

    expect(screen.queryByTestId('role-create-global-hint')).toBeNull();
    fireEvent.change(await scopeSelect(4), { target: { value: 'global' } });

    expect(await screen.findByTestId('role-create-global-hint')).toBeInTheDocument();
  });
});

describe('The create wire grammar (#888)', () => {
  function capturingTransport(): { transport: Transport; sent: () => unknown } {
    let body: unknown;
    const transport: Transport = {
      request: jest.fn().mockImplementation(async (_m: string, _p: string, sentBody?: unknown) => {
        body = sentBody;
        return { status: 201, body: { data: { id: 1 } } };
      }),
    };
    return { transport, sent: () => body };
  }

  const BASE = { name: 'Nurse', description: 'Ward nurse', permissions: [] };

  it('omits both ownership fields when no scope is given', async () => {
    const { transport, sent } = capturingTransport();
    await createRolesAdapter(transport).createRole(BASE);

    expect(sent()).not.toHaveProperty('tenant_id');
    expect(sent()).not.toHaveProperty('global');
  });

  it("omits them for the explicit 'own' scope too — same request, not a third value", async () => {
    const { transport, sent } = capturingTransport();
    await createRolesAdapter(transport).createRole({ ...BASE, scope: 'own' });

    expect(sent()).not.toHaveProperty('tenant_id');
    expect(sent()).not.toHaveProperty('global');
  });

  it('sends tenant_id for a named tenant, and never a null one', async () => {
    const { transport, sent } = capturingTransport();
    await createRolesAdapter(transport).createRole({ ...BASE, scope: 7 });

    expect(sent()).toMatchObject({ tenant_id: 7 });
    expect(sent()).not.toHaveProperty('global');
  });

  it('sends global: true — the separate unambiguous form — and no tenant_id', async () => {
    const { transport, sent } = capturingTransport();
    await createRolesAdapter(transport).createRole({ ...BASE, scope: 'global' });

    expect(sent()).toMatchObject({ global: true });
    expect(sent()).not.toHaveProperty('tenant_id');
  });

  it('never sends the package-level `scope` key itself', async () => {
    const { transport, sent } = capturingTransport();
    await createRolesAdapter(transport).createRole({ ...BASE, scope: 7 });

    expect(sent()).not.toHaveProperty('scope');
  });
});
