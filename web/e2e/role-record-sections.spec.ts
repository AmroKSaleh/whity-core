import { test, expect } from './support/fixtures';
import { uniqueSuffix } from './support/constants';
import { deleteRole, findRoleIdByName } from './support/api';

/**
 * #910 — the role record's REGIONS are gated separately, against the live stack.
 *
 * The unit and real-engine suites drive the whole matrix, including the state
 * this spec cannot reach: a HIDDEN region needs a caller who holds the `admin`
 * role (the roles routes' gate) but not `permissions:read`, which is a role only
 * an operator would build. What a browser CAN prove, and what nothing below the
 * browser can, is that the two reachable states arrive as a real page:
 *
 *  - both regions editable, on a role this tenant owns;
 *  - both regions read-only WITH a stated reason, on a global base role, and
 *    with no inputs at all rather than disabled ones.
 *
 * The second is the case #886 came from and #897 made a first-class state: an
 * operator opening a deployment-wide role must be told why they cannot change
 * it, not handed a form that would 404 on save.
 */
test.describe('Role record regions (#910)', () => {
  let createdRoleName: string | null = null;

  test.afterEach(async ({ adminApi }) => {
    if (createdRoleName) {
      const id = await findRoleIdByName(adminApi, createdRoleName);
      if (id !== null) await deleteRole(adminApi, id);
      createdRoleName = null;
    }
  });

  test('a tenant-owned role: both regions editable, and a save reaches both', async ({
    adminPage,
    page,
    adminApi,
  }) => {
    createdRoleName = `e2e-role-regions-${uniqueSuffix()}`;

    await adminPage.shell.clickNav('Roles');
    await page.waitForURL('**/admin/roles');

    await page.getByRole('button', { name: 'Create Role' }).click();
    const createDialog = page.getByRole('dialog');
    await createDialog.getByLabel('Role Name').fill(createdRoleName);
    await createDialog.getByLabel('Description').fill('Region probe');
    await createDialog.getByRole('button', { name: 'Create Role' }).click();
    await expect(page.getByText('Role created successfully')).toBeVisible();

    const row = page.getByRole('row', { name: new RegExp(createdRoleName) });
    await row.getByRole('button', { name: 'Actions' }).click();
    await page.getByRole('menuitem', { name: 'Edit' }).click();
    await page.waitForURL(/\/admin\/roles\/\d+$/);

    // Both regions present, and neither carries a read-only explanation: the
    // seeded admin holds `roles:write`, `roles:manage` and `permissions:read`
    // (migration 110 grants the last two to whoever already held the first).
    const details = page.getByTestId('role-record-section-details');
    const permissions = page.getByTestId('role-record-section-permissions');
    await expect(details).toHaveAttribute('data-section-state', 'editable');
    await expect(permissions).toHaveAttribute('data-section-state', 'editable');
    await expect(page.getByTestId('role-record-readonly-notice')).toHaveCount(0);
    await expect(page.getByTestId('role-record-section-details-readonly')).toHaveCount(0);
    await expect(page.getByTestId('role-record-section-permissions-readonly')).toHaveCount(0);

    // One save, reaching BOTH regions — the granularity must not have cost the
    // ordinary case its single submit.
    await page.getByLabel('Description').fill('Region probe, edited');
    const rolesRead = page.getByTestId('perm-grid').locator('label', { hasText: 'roles:read' });
    await rolesRead.click();
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByText('Role updated successfully')).toBeVisible();

    const id = await findRoleIdByName(adminApi, createdRoleName);
    const detail = await adminApi.get(`/api/v1/roles/${id}`);
    const body = (await detail.json()) as {
      data?: { description?: string; permissions?: Array<{ name: string }> };
    };
    expect(body.data?.description).toBe('Region probe, edited');
    expect((body.data?.permissions ?? []).map((p) => p.name)).toContain('roles:read');
  });

  test('a global base role: every region read-only, said once, with no inputs', async ({
    adminPage,
    page,
  }) => {
    await adminPage.shell.clickNav('Roles');
    await page.waitForURL('**/admin/roles');

    // The seeded `user` role is GLOBAL (NULL tenant), so this tenant may read it
    // and may not write it (WC-110). Its Edit row action is disabled, so the
    // record is reached by its own address — which a record page has, and which
    // is the other half of why it beats a dialog.
    const userRow = page.getByRole('row', { name: /^user/ });
    await userRow.getByRole('button', { name: 'Actions' }).click();
    await page.getByRole('menuitem', { name: 'Open record' }).click();
    await page.waitForURL(/\/admin\/roles\/\d+$/);

    await expect(page.getByTestId('role-record-badge-global')).toBeVisible();

    // BOTH regions refused, for the RECORD's own reason rather than anything
    // this caller lacks — so the shell says it ONCE above the page instead of
    // paraphrasing one fact under every heading.
    await expect(page.getByTestId('role-record-section-details')).toHaveAttribute(
      'data-section-state',
      'read-only'
    );
    await expect(page.getByTestId('role-record-section-permissions')).toHaveAttribute(
      'data-section-state',
      'read-only'
    );
    await expect(page.getByTestId('role-record-readonly-notice')).toContainText(
      'Only the system tenant can change it'
    );

    // A state, not a disabled form: no inputs at all, and no save.
    await expect(page.getByRole('button', { name: 'Save changes' })).toHaveCount(0);
    await expect(page.getByTestId('role-record-section-details').locator('input')).toHaveCount(0);
    await expect(
      page.getByTestId('role-record-section-permissions').locator('input[type="checkbox"]')
    ).toHaveCount(0);

    // The region still SHOWS the role's grants — read-only means "may look".
    await expect(page.getByTestId('role-record-section-permissions')).toBeVisible();
  });
});
