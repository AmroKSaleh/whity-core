import { test, expect } from './support/fixtures';
import { uniqueSuffix } from './support/constants';
import { deleteRole, findRoleIdByName } from './support/api';

/**
 * Roles CRUD against the live backend (role hierarchy + permission registry).
 *
 * Discipline: every created role uses a unique suffixed name and is removed in
 * afterEach via the API (belt-and-suspenders even when the UI delete already
 * ran). The seeded `admin` and `user` roles are never mutated.
 */
test.describe('Roles CRUD (admin)', () => {
  let createdRoleName: string | null = null;

  test.afterEach(async ({ adminApi }) => {
    if (createdRoleName) {
      const id = await findRoleIdByName(adminApi, createdRoleName);
      if (id !== null) {
        await deleteRole(adminApi, id);
      }
      createdRoleName = null;
    }
  });

  test('seeded roles are listed', async ({ adminPage, page }) => {
    await adminPage.shell.clickNav('Roles');
    await page.waitForURL('**/admin/roles');
    const table = page.getByRole('table');
    await expect(table.getByRole('cell', { name: 'admin', exact: true })).toBeVisible();
    await expect(table.getByRole('cell', { name: 'user', exact: true })).toBeVisible();
  });

  test('create a role, see it listed, then delete it', async ({ adminPage, page }) => {
    createdRoleName = `e2e-role-${uniqueSuffix()}`;

    await adminPage.shell.clickNav('Roles');
    await page.waitForURL('**/admin/roles');

    // --- Create ---
    await page.getByRole('button', { name: 'Create Role' }).click();
    const createDialog = page.getByRole('dialog');
    await expect(createDialog.getByText('Create New Role')).toBeVisible();

    await createDialog.getByLabel('Role Name').fill(createdRoleName);
    await createDialog.getByLabel('Description').fill('Created by the E2E suite');
    await createDialog.getByRole('button', { name: 'Create Role' }).click();

    // Success toast + row appears in the table.
    await expect(page.getByText('Role created successfully')).toBeVisible();
    const row = page.getByRole('row', { name: new RegExp(createdRoleName) });
    await expect(row).toBeVisible();

    // --- Delete ---
    await row.getByRole('button').click(); // row actions menu trigger
    await page.getByRole('menuitem', { name: 'Delete' }).click();
    const deleteDialog = page.getByRole('dialog');
    await expect(deleteDialog.getByRole('heading', { name: 'Delete Role' })).toBeVisible();
    await deleteDialog.getByRole('button', { name: 'Delete Role' }).click();

    await expect(page.getByText('Role deleted successfully')).toBeVisible();
    await expect(page.getByRole('row', { name: new RegExp(createdRoleName) })).toHaveCount(0);

    createdRoleName = null; // already deleted; skip afterEach cleanup
  });

  test('view permissions opens the permissions panel with colon-notation perms', async ({
    adminPage,
    page,
  }) => {
    await adminPage.shell.clickNav('Roles');
    await page.waitForURL('**/admin/roles');

    // Open the row-actions menu on the seeded `admin` role and view perms.
    const adminRow = page.getByRole('row', { name: /^admin/ });
    await adminRow.getByRole('button').click();
    await page.getByRole('menuitem', { name: 'View Permissions' }).click();

    // The permissions panel header names the role, and the seeded admin role
    // carries colon-notation permissions (ous:* in this seed).
    const panel = page.getByRole('dialog');
    await expect(panel.getByText('admin - Permissions')).toBeVisible();
    await expect(panel.getByText(/ous:read/)).toBeVisible();
  });

  // KNOWN APP BUG (#4): the Create Role modal posts the selected permissions
  // under the key `permissionIds`, but the backend `POST /api/roles` only
  // honours the key `permissions` (verified: posting `permissions:[1]` assigns
  // it, `permissionIds:[1]` does not; the Edit modal correctly uses
  // `permissions`). So permissions chosen at create time are silently dropped —
  // the role is created with ZERO permissions despite the UI showing
  // "1 permission selected". The role still gets created, so create/list/delete
  // is unaffected; only the assign-at-create persistence is broken.
  test.fixme('assign a permission while creating a role', async ({
    adminPage,
    page,
    adminApi,
  }) => {
    createdRoleName = `e2e-role-perm-${uniqueSuffix()}`;

    await adminPage.shell.clickNav('Roles');
    await page.waitForURL('**/admin/roles');

    await page.getByRole('button', { name: 'Create Role' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Create New Role')).toBeVisible();

    await dialog.getByLabel('Role Name').fill(createdRoleName);
    await dialog.getByLabel('Description').fill('E2E role with a permission');

    // The permissions selector is a collapsed dropdown whose label changes from
    // "Select permissions..." to "N permissions selected"; match either state
    // so the same stable locator works before and after selection. The whole
    // widget remounts from react-hook-form state on each change, so the
    // toggle/checkbox can detach mid-action — open it with a forced click.
    const permsToggle = dialog
      .getByRole('button')
      .filter({ hasText: /permission/i });
    await permsToggle.click({ force: true });

    // Each permission is a <label> wrapping a controlled checkbox + its name.
    // Click the label (fires the checkbox onChange); a forced click avoids the
    // remount-induced instability and the controlled-state re-read pitfall.
    const usersReadLabel = dialog.locator('label', { hasText: 'users:read' });
    await expect(usersReadLabel).toBeVisible();
    await usersReadLabel.click({ force: true });
    await expect(permsToggle).toHaveText(/1 permission selected/);

    // Submit directly (force, in case the open dropdown overlays the footer).
    await dialog.getByRole('button', { name: 'Create Role' }).click({ force: true });
    await expect(page.getByText('Role created successfully')).toBeVisible();

    // The new role should appear in the table...
    const row = page.getByRole('row', { name: new RegExp(createdRoleName) });
    await expect(row).toBeVisible();

    // ...and actually carry the assigned permission. Verify via the API, which
    // is more robust than parsing the rendered permission-count cell.
    const id = await findRoleIdByName(adminApi, createdRoleName);
    expect(id, 'created role should be retrievable by name').not.toBeNull();
    const perms = await adminApi.get(`/api/roles/${id}/permissions`);
    expect(perms.ok()).toBeTruthy();
    const body = (await perms.json()) as { data?: Array<{ name: string }> };
    expect((body.data ?? []).map((p) => p.name)).toContain('users:read');
  });
});
