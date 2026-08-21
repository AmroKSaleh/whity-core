/**
 * #882: the user RECORD page — the record-page shell's SECOND consumer, and the
 * screen that made the shell general rather than roles-shaped.
 *
 * What is pinned here, and why each of them is a decision rather than a detail:
 *
 *  1. **The authority history is finally READ.** #889/#890 audited membership
 *     grants and revocations against the USER, so `target_type=user&target_id=N`
 *     is one person's complete authority history in one query — and a revocation
 *     row is the ONLY surviving record of a deleted membership. If this page
 *     stopped rendering `role_name`/`granted_at`, "what was lost" would silently
 *     disappear from the product while the data kept accruing.
 *  2. **Missing `audit:read` is a clean ABSENCE.** It is a separate permission
 *     from user administration, so the panel is not in the document — not an
 *     error box about a capability the operator deliberately withheld.
 *  3. **Read-only is a state.** Without `users:write` there are no pickers at
 *     all, and the page says why.
 *  4. **A partial OU list is WITHHELD, never offered short.** A short list of
 *     units is indistinguishable from a correct one, and acting on it moves a
 *     real person into the wrong unit.
 *  5. **The adapter's wire translation** — `ou_id` in and out, the 403 and 404
 *     sentinels, and the paginated OU walk that must complete or report that it
 *     did not.
 *
 * Driven through the data-source-agnostic `UserRecordScreen`
 * (@amroksaleh/features/users) with injected props, like the roles tests.
 */

import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { UserRecordScreen } from '@amroksaleh/features/users';
import type { UserMembership, UserRecord, UsersAdapter } from '@amroksaleh/features/users';
import type { Transport } from '@amroksaleh/features/roles';
import { createUsersAdapter } from '@/lib/users-adapter';

/** English-fallback translator: returns the caller-supplied source string. */
const t = (_key: string, fallback?: string, vars?: Record<string, string | number>): string => {
  const text = fallback ?? _key;
  if (!vars) return text;
  return Object.entries(vars).reduce((acc, [k, v]) => acc.replaceAll(`{${k}}`, String(v)), text);
};

const USER: UserRecord = {
  id: 42,
  name: 'sara',
  email: 'sara@example.test',
  role: 'manager',
  tenantId: 1,
  ouId: 7,
  createdAt: '2026-02-03T09:00:00Z',
  status: 'active',
  accountStatus: 'active',
};

const MEMBERSHIPS: UserMembership[] = [
  {
    id: 100,
    tenantId: 1,
    tenantName: 'Acme',
    roleId: 2,
    role: 'manager',
    ouId: 7,
    isPrimary: true,
    status: 'active',
  },
  {
    id: 101,
    tenantId: 1,
    tenantName: 'Acme',
    roleId: 3,
    role: 'approver',
    ouId: null,
    isPrimary: false,
    status: 'active',
  },
];

function fakeAdapter(over: Partial<UsersAdapter> = {}): UsersAdapter {
  return {
    getUser: jest.fn().mockResolvedValue(USER),
    listUserMemberships: jest.fn().mockResolvedValue(MEMBERSHIPS),
    getUserActivity: jest.fn().mockResolvedValue([]),
    listRoleNames: jest.fn().mockResolvedValue(['user', 'manager', 'approver']),
    listOus: jest
      .fn()
      .mockResolvedValue({ options: [{ id: 7, name: 'Support' }, { id: 8, name: 'Field' }], complete: true }),
    updateUser: jest.fn().mockResolvedValue('ok'),
    sendPasswordResetLink: jest.fn().mockResolvedValue(undefined),
    getCapabilities: jest.fn().mockResolvedValue([]),
    ...over,
  };
}

const canAll = () => true;

function renderRecord(props: Partial<React.ComponentProps<typeof UserRecordScreen>> = {}) {
  const adapter = props.adapter ?? fakeAdapter();
  const onBack = props.onBack ?? jest.fn();
  render(
    <UserRecordScreen
      adapter={adapter}
      userId={props.userId ?? 42}
      can={props.can ?? canAll}
      t={t}
      onNotify={props.onNotify}
      onBack={onBack}
    />
  );
  return { adapter, onBack };
}

describe('UserRecordScreen — the record and what it states', () => {
  it('loads the person by the id the route gave it, and titles the page with them', async () => {
    const { adapter } = renderRecord({ userId: 42 });

    expect(await screen.findByTestId('user-record')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'sara' })).toBeInTheDocument();
    // The email is both the header subtitle and a stated field, so it is on the
    // page twice on purpose.
    expect(screen.getAllByText('sara@example.test').length).toBeGreaterThan(0);
    expect(adapter.getUser).toHaveBeenCalledWith(42);
    // Once per mount — a refetch loop would show as a second call.
    expect(adapter.getUser).toHaveBeenCalledTimes(1);
  });

  it("states the person's role, unit and joined date from the server", async () => {
    renderRecord();

    expect(await screen.findByTestId('user-record-stat-role')).toHaveTextContent('manager');
    // The unit's NAME, resolved from the OU list rather than left as an id.
    await waitFor(() =>
      expect(screen.getByTestId('user-record-stat-ou')).toHaveTextContent('Support')
    );
    await waitFor(() =>
      expect(screen.getByTestId('user-record-stat-memberships')).toHaveTextContent('2')
    );
  });

  /**
   * The two statuses answer different questions — the global account switch and
   * this tenant's membership lifecycle — so the page states both rather than
   * collapsing them into one "Status" that would be confidently wrong about the
   * other. Same doctrine as #895, one level down.
   */
  it('badges a globally deactivated account and an invited membership SEPARATELY', async () => {
    renderRecord({
      adapter: fakeAdapter({
        getUser: jest
          .fn()
          .mockResolvedValue({ ...USER, accountStatus: 'inactive', status: 'invited' }),
      }),
    });

    expect(await screen.findByTestId('user-record-badge-deactivated')).toHaveTextContent(
      'Deactivated'
    );
    expect(screen.getByTestId('user-record-badge-invited')).toHaveTextContent('Invited');
  });

  it('renders an error state with a way back when the person cannot be loaded', async () => {
    const user = userEvent.setup();
    const onBack = jest.fn();
    renderRecord({
      adapter: fakeAdapter({ getUser: jest.fn().mockRejectedValue(new Error('User not found')) }),
      onBack,
    });

    expect(await screen.findByText('This person could not be loaded')).toBeInTheDocument();
    // The server's own sentence, which beats the generic fallback here.
    expect(screen.getByText('User not found')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Back to users' }));
    expect(onBack).toHaveBeenCalled();
  });
});

describe('UserRecordScreen — the authority history #889/#890 made possible', () => {
  it('asks the audit trail for THIS person, by target type and target id', async () => {
    const { adapter } = renderRecord({ userId: 77 });

    await waitFor(() => expect(adapter.getUserActivity).toHaveBeenCalled());
    expect((adapter.getUserActivity as jest.Mock).mock.calls[0][0]).toBe(77);
  });

  /**
   * The point of the whole panel: a revocation DELETES the membership row, so
   * the audit row's metadata is the only surviving record of which role was
   * taken away and how long it was held.
   */
  it('says what a revocation removed, and how long it was held', async () => {
    renderRecord({
      adapter: fakeAdapter({
        getUserActivity: jest.fn().mockResolvedValue([
          {
            id: 9,
            action: 'user.membership.removed',
            actorUserId: 3,
            createdAt: '2026-08-20T12:00:00Z',
            metadata: {
              membership_id: 101,
              role_id: 3,
              role_name: 'approver',
              ou_id: null,
              status: 'active',
              granted_at: '2026-03-01T09:00:00Z',
            },
          },
          {
            id: 8,
            action: 'user.membership.added',
            actorUserId: null,
            createdAt: '2026-03-01T09:00:00Z',
            metadata: { membership_id: 101, role_id: 3, role_name: 'approver', is_primary: false },
          },
        ]),
      }),
    });

    const panel = await screen.findByTestId('user-record-activity');
    const [revocation, grant] = within(panel).getAllByRole('listitem');

    // The revocation names the role that was taken away and how long it was
    // held — neither of which exists anywhere else once the membership row is
    // gone.
    expect(within(revocation).getByText('user.membership.removed')).toBeInTheDocument();
    expect(revocation).toHaveTextContent('role approver');
    expect(revocation).toHaveTextContent(/held since/);
    expect(revocation).toHaveTextContent('by user 3');

    // A grant with no actor is the system's, not a blank.
    expect(within(grant).getByText('user.membership.added')).toBeInTheDocument();
    expect(grant).toHaveTextContent('by the system');
  });

  it('OMITS the history panel entirely when the caller lacks audit:read', async () => {
    renderRecord({
      adapter: fakeAdapter({ getUserActivity: jest.fn().mockResolvedValue('forbidden') }),
    });

    // The record loaded…
    expect(await screen.findByTestId('user-record')).toBeInTheDocument();
    // …and the panel is absent rather than present-and-complaining.
    await waitFor(() =>
      expect(screen.queryByTestId('user-record-activity')).not.toBeInTheDocument()
    );
    expect(screen.queryByText('Authority history')).not.toBeInTheDocument();
  });

  it('keeps the record usable when the history panel fails', async () => {
    renderRecord({
      adapter: fakeAdapter({ getUserActivity: jest.fn().mockRejectedValue(new Error('nope')) }),
    });

    expect(await screen.findByTestId('user-record')).toBeInTheDocument();
    const panel = screen.getByTestId('user-record-activity');
    expect(within(panel).getByText("Failed to load this person's history")).toBeInTheDocument();
  });
});

describe('UserRecordScreen — memberships', () => {
  it('lists every tenant and role, marking the primary one', async () => {
    renderRecord();

    const panel = await screen.findByTestId('user-record-memberships');
    const items = within(panel).getAllByRole('listitem');
    expect(items).toHaveLength(2);
    expect(items[0]).toHaveTextContent('Acme');
    expect(items[0]).toHaveTextContent('manager');
    expect(within(items[0]).getByText('Primary')).toBeInTheDocument();
    expect(within(items[1]).queryByText('Primary')).not.toBeInTheDocument();
  });

  it('says the person belongs to no tenant rather than showing an empty panel', async () => {
    renderRecord({ adapter: fakeAdapter({ listUserMemberships: jest.fn().mockResolvedValue([]) }) });

    const panel = await screen.findByTestId('user-record-memberships');
    expect(within(panel).getByText('This person belongs to no tenant.')).toBeInTheDocument();
  });
});

describe('UserRecordScreen — editing', () => {
  it('saves the chosen role through the adapter', async () => {
    const user = userEvent.setup();
    const updateUser = jest.fn().mockResolvedValue('ok');
    const onNotify = jest.fn();
    renderRecord({ adapter: fakeAdapter({ updateUser }), onNotify });

    await screen.findByTestId('user-record-role');
    await user.click(screen.getByTestId('user-record-role'));
    await user.click(await screen.findByRole('option', { name: 'approver' }));

    await user.click(screen.getByRole('button', { name: 'Save changes' }));

    await waitFor(() => expect(updateUser).toHaveBeenCalled());
    expect(updateUser.mock.calls[0][0]).toBe(42);
    expect(updateUser.mock.calls[0][1]).toEqual({ role: 'approver', ouId: 7 });
    expect(onNotify).toHaveBeenCalledWith('User updated successfully', 'success');
  });

  it('cannot save an unchanged record — Save is inert until something differs', async () => {
    renderRecord();

    await screen.findByTestId('user-record');
    expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled();
  });

  it('says so in its own words when the person has left the tenant between load and save', async () => {
    const user = userEvent.setup();
    const onNotify = jest.fn();
    renderRecord({
      adapter: fakeAdapter({ updateUser: jest.fn().mockResolvedValue('not-found') }),
      onNotify,
    });

    await screen.findByTestId('user-record-role');
    await user.click(screen.getByTestId('user-record-role'));
    await user.click(await screen.findByRole('option', { name: 'approver' }));
    await user.click(screen.getByRole('button', { name: 'Save changes' }));

    await waitFor(() =>
      expect(onNotify).toHaveBeenCalledWith('This person is no longer in your tenant.', 'error')
    );
  });

  it('sends a reset LINK rather than ever showing a credential', async () => {
    const user = userEvent.setup();
    const sendPasswordResetLink = jest.fn().mockResolvedValue(undefined);
    const onNotify = jest.fn();
    renderRecord({ adapter: fakeAdapter({ sendPasswordResetLink }), onNotify });

    await user.click(await screen.findByTestId('user-record-send-reset-link'));

    await waitFor(() => expect(sendPasswordResetLink).toHaveBeenCalledWith(42));
    expect(onNotify).toHaveBeenCalledWith(
      'A password-reset link has been sent to sara@example.test',
      'success'
    );
    // No password field exists anywhere on the page.
    expect(document.querySelectorAll('input[type="password"]')).toHaveLength(0);
  });

  /**
   * A short list of units is indistinguishable from a correct one, and acting on
   * it moves a real person into a unit nobody chose. The picker is withheld, the
   * current unit is still stated, and the rest of the form still edits the role.
   */
  it('withholds the OU picker when the unit list could not be completed', async () => {
    renderRecord({
      adapter: fakeAdapter({
        listOus: jest.fn().mockResolvedValue({ options: [{ id: 8, name: 'Field' }], complete: false }),
      }),
    });

    expect(await screen.findByTestId('user-record-ou-withheld')).toBeInTheDocument();
    expect(screen.queryByTestId('user-record-ou')).not.toBeInTheDocument();
    // The unit is still named as far as it can be — "unit 7, name unknown", not
    // "no unit", which is a different fact.
    expect(screen.getByTestId('user-record-stat-ou')).toHaveTextContent('Unit #7');
    // …and the role picker is untouched.
    expect(screen.getByTestId('user-record-role')).toBeInTheDocument();
  });
});

describe('UserRecordScreen — read-only is a state, not a disabled form', () => {
  it('renders no pickers at all, and says why, for a caller without users:write', async () => {
    renderRecord({ can: () => false });

    expect(
      await screen.findByText(
        "You don't have permission to edit users, so this record is read-only."
      )
    ).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Save changes' })).not.toBeInTheDocument();
    expect(screen.queryByTestId('user-record-role')).not.toBeInTheDocument();
    expect(screen.queryByTestId('user-record-ou')).not.toBeInTheDocument();
    // Not a disabled reset button either — the whole affordance is absent.
    expect(screen.queryByTestId('user-record-send-reset-link')).not.toBeInTheDocument();
  });

  it('still states the record, and still shows the side panels', async () => {
    renderRecord({ can: () => false });

    expect(await screen.findByTestId('user-record-memberships')).toBeInTheDocument();
    expect(screen.getByTestId('user-record-stat-role')).toHaveTextContent('manager');
  });
});

/**
 * The adapter half — the wire translation the record screen never sees. Driven
 * through `createUsersAdapter` over a stub transport, exactly the way the roles
 * adapter's own tests are.
 */
describe('web users adapter', () => {
  function transportReturning(status: number, body: unknown = {}): Transport {
    return { request: jest.fn().mockResolvedValue({ status, body }) };
  }

  it('maps the wire\'s snake_case ou_id onto the record\'s ouId', async () => {
    const adapter = createUsersAdapter(
      transportReturning(200, {
        data: {
          id: 42,
          name: 'sara',
          email: 'sara@example.test',
          role: 'manager',
          tenantId: 1,
          ou_id: 7,
          createdAt: '2026-02-03',
          status: 'active',
          accountStatus: 'active',
        },
      })
    );

    const user = await adapter.getUser(42);
    expect(user.ouId).toBe(7);
  });

  it('sends ou_id back as ou_id, including the null that clears it', async () => {
    const transport = transportReturning(200, { data: {} });
    const adapter = createUsersAdapter(transport);

    await adapter.updateUser(42, { role: 'user', ouId: null });

    const [method, path, body] = (transport.request as jest.Mock).mock.calls[0];
    expect(method).toBe('PATCH');
    expect(path).toBe('/api/v1/users/42');
    expect(body).toEqual({ role: 'user', ou_id: null });
  });

  it("asks the audit trail for this PERSON's entries, by target type and target id", async () => {
    const transport = transportReturning(200, { data: [] });
    const adapter = createUsersAdapter(transport);

    await adapter.getUserActivity(42, 5);

    const [, path] = (transport.request as jest.Mock).mock.calls[0];
    expect(path).toContain('target_type=user');
    expect(path).toContain('target_id=42');
    expect(path).toContain('per_page=5');
  });

  it('maps a 403 on the audit trail to the "forbidden" sentinel, not an error', async () => {
    const adapter = createUsersAdapter(transportReturning(403));
    await expect(adapter.getUserActivity(42)).resolves.toBe('forbidden');
  });

  it('still throws on a real audit failure, so it is not confused with an ungranted capability', async () => {
    const adapter = createUsersAdapter(transportReturning(500));
    await expect(adapter.getUserActivity(42)).rejects.toThrow();
  });

  it('maps a 404 on save to "not-found" rather than a raw failure', async () => {
    const adapter = createUsersAdapter(transportReturning(404));
    await expect(adapter.updateUser(42, { role: 'user', ouId: null })).resolves.toBe('not-found');
  });

  it('walks EVERY page of organisational units before reporting the list complete', async () => {
    const pages = [
      { status: 200, body: { data: [{ id: 1, name: 'A' }], pagination: { total: 3 } } },
      { status: 200, body: { data: [{ id: 2, name: 'B' }], pagination: { total: 3 } } },
      { status: 200, body: { data: [{ id: 3, name: 'C' }], pagination: { total: 3 } } },
    ];
    let call = 0;
    const transport: Transport = {
      request: jest.fn().mockImplementation(() => Promise.resolve(pages[call++])),
    };

    const result = await createUsersAdapter(transport).listOus();

    expect(result.complete).toBe(true);
    expect(result.options.map((ou) => ou.id)).toEqual([1, 2, 3]);
    expect((transport.request as jest.Mock)).toHaveBeenCalledTimes(3);
  });

  /**
   * The failure mode this flag exists for: two of three units arrived, so the
   * list is SHORT — and a short list looks exactly like a correct one. Nothing is
   * offered rather than a subset.
   */
  it('reports an incomplete unit list as incomplete, and offers nothing', async () => {
    let call = 0;
    const transport: Transport = {
      request: jest.fn().mockImplementation(() =>
        Promise.resolve(
          call++ === 0
            ? { status: 200, body: { data: [{ id: 1, name: 'A' }], pagination: { total: 3 } } }
            : { status: 500, body: {} }
        )
      ),
    };

    const result = await createUsersAdapter(transport).listOus();

    expect(result.complete).toBe(false);
    expect(result.options).toEqual([]);
  });
});
