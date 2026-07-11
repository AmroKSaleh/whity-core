import { test, expect } from './support/fixtures';
import { uniqueSuffix } from './support/constants';
import {
  assignUserRole,
  deleteRole,
  deleteUser,
  findRoleIdByName,
  findUserIdByEmail,
} from './support/api';

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

  // WC-99 regression guard: the Create Role modal must POST the selected
  // permissions under the canonical key `permissions` (the same key the Edit
  // modal uses), not `permissionIds`. Verified: `POST /api/roles` honours
  // `permissions` and assigns the grant, whereas `permissionIds` was silently
  // dropped, creating the role with ZERO permissions despite the UI showing
  // "1 permission selected". This test selects one permission at create time
  // and asserts it is actually persisted on the new role.
  test('assign a permission while creating a role', async ({
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
    // so the same stable locator works before and after selection. The toggle
    // is type="button" (WC-99), so opening it must NOT submit the form.
    const permsToggle = dialog
      .getByRole('button')
      .filter({ hasText: /permission/i });
    await permsToggle.click();

    // Each permission is a <label> wrapping a controlled checkbox + its name;
    // clicking it fires the checkbox onChange and updates form state.
    const usersReadLabel = dialog.locator('label', { hasText: 'users:read' });
    await expect(usersReadLabel).toBeVisible();
    await usersReadLabel.click();
    await expect(permsToggle).toHaveText(/1 permission selected/);

    // Close the dropdown so its panel no longer overlays the footer, then
    // submit. The role must be created only by this explicit submit — never by
    // toggling the permissions dropdown (the WC-99 premature-submit bug).
    await permsToggle.click();
    await dialog.getByRole('button', { name: 'Create Role' }).click();
    await expect(page.getByText('Role created successfully')).toBeVisible();

    // The new role should appear in the table...
    const row = page.getByRole('row', { name: new RegExp(createdRoleName) });
    await expect(row).toBeVisible();

    // ...and actually carry the assigned permission. Verify via the API, which
    // is more robust than parsing the rendered permission-count cell.
    const id = await findRoleIdByName(adminApi, createdRoleName);
    expect(id, 'created role should be retrievable by name').not.toBeNull();
    const perms = await adminApi.get(`/api/v1/roles/${id}/permissions`);
    expect(perms.ok()).toBeTruthy();
    const body = (await perms.json()) as { data?: Array<{ name: string }> };
    expect((body.data ?? []).map((p) => p.name)).toContain('users:read');
  });

  // Edit flow: open a role with no permissions, add one through the Edit modal's
  // permission picker, Save, and assert the new permission count is reflected in
  // the table (the Permission Count cell) AND persisted server-side. This covers
  // the "edit/reassign permissions" + "count persists" interaction (WC-99) from
  // the editing side, complementing the create-side guard above.
  test('edit a role to add a permission; the count persists', async ({
    adminPage,
    page,
    adminApi,
  }) => {
    createdRoleName = `e2e-role-edit-${uniqueSuffix()}`;

    await adminPage.shell.clickNav('Roles');
    await page.waitForURL('**/admin/roles');

    // Create a bare role (no permissions) to edit.
    await page.getByRole('button', { name: 'Create Role' }).click();
    const createDialog = page.getByRole('dialog');
    await createDialog.getByLabel('Role Name').fill(createdRoleName);
    await createDialog.getByLabel('Description').fill('Edit target');
    await createDialog.getByRole('button', { name: 'Create Role' }).click();
    await expect(page.getByText('Role created successfully')).toBeVisible();

    const row = page.getByRole('row', { name: new RegExp(createdRoleName) });
    await expect(row).toBeVisible();
    // The fresh role shows a 0 permission count.
    await expect(row.getByRole('cell', { name: '0', exact: true })).toBeVisible();

    // --- Edit: add a permission ---
    await row.getByRole('button').click();
    await page.getByRole('menuitem', { name: 'Edit' }).click();
    const editDialog = page.getByRole('dialog');
    await expect(editDialog.getByRole('heading', { name: 'Edit Role' })).toBeVisible();

    const permsToggle = editDialog
      .getByRole('button')
      .filter({ hasText: /Select permissions|permission/i });
    await permsToggle.click();
    const rolesReadLabel = editDialog.locator('label', { hasText: 'roles:read' });
    await expect(rolesReadLabel).toBeVisible();
    await rolesReadLabel.click();
    await expect(permsToggle).toHaveText(/1 permission selected/);
    // Collapse the dropdown so its panel does not overlay the footer.
    await permsToggle.click();
    await editDialog.getByRole('button', { name: 'Save Changes' }).click();
    await expect(page.getByText('Role updated successfully')).toBeVisible();

    // The table's Permission Count cell for this row now reads 1...
    const updatedRow = page.getByRole('row', { name: new RegExp(createdRoleName) });
    await expect(updatedRow.getByRole('cell', { name: '1', exact: true })).toBeVisible();

    // ...and the assigned permission is persisted server-side.
    const id = await findRoleIdByName(adminApi, createdRoleName);
    expect(id).not.toBeNull();
    const perms = await adminApi.get(`/api/v1/roles/${id}/permissions`);
    const body = (await perms.json()) as { data?: Array<{ name: string }> };
    expect((body.data ?? []).map((p) => p.name)).toContain('roles:read');
  });

  // The permission picker exposes a "Select All" toggle that flips to "Deselect
  // All" once everything is checked. This exercises that control directly: after
  // Select All the toggle label reflects the full count, and Deselect All clears
  // it back to "Select permissions...".
  test('permission picker Select All / Deselect All toggles every checkbox', async ({
    adminPage,
    page,
  }) => {
    createdRoleName = `e2e-role-selectall-${uniqueSuffix()}`;

    await adminPage.shell.clickNav('Roles');
    await page.waitForURL('**/admin/roles');

    await page.getByRole('button', { name: 'Create Role' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Role Name').fill(createdRoleName);
    await dialog.getByLabel('Description').fill('Select-all probe');

    const permsToggle = dialog.getByRole('button').filter({ hasText: /permission/i });
    await permsToggle.click();

    // Select All: the toggle label switches to "Deselect All" and the trigger
    // reflects the full permission count (the seed exposes 17 permissions).
    const selectAll = dialog.getByRole('button', { name: 'Select All' });
    await selectAll.click();
    await expect(dialog.getByRole('button', { name: 'Deselect All' })).toBeVisible();
    await expect(permsToggle).toHaveText(/\d+ permissions selected/);

    // Deselect All: back to the empty placeholder.
    await dialog.getByRole('button', { name: 'Deselect All' }).click();
    await expect(dialog.getByRole('button', { name: 'Select All' })).toBeVisible();
    await expect(permsToggle).toHaveText(/Select permissions/);

    // Cancel without creating: this role was never persisted.
    await permsToggle.click();
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();
    createdRoleName = null;
  });

  // The picker groups permissions by resource, filters on a live search, and
  // offers a per-group select-all — the granular-RBAC ergonomics (WC-roles-ux).
  test('permission picker groups by resource with search and per-group select-all', async ({
    adminPage,
    page,
  }) => {
    createdRoleName = `e2e-role-group-${uniqueSuffix()}`;

    await adminPage.shell.clickNav('Roles');
    await page.waitForURL('**/admin/roles');

    await page.getByRole('button', { name: 'Create Role' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Role Name').fill(createdRoleName);
    await dialog.getByLabel('Description').fill('Grouping probe');

    const permsToggle = dialog.getByRole('button').filter({ hasText: /permission/i });
    await permsToggle.click();

    // Live search narrows the list to the users:* group.
    await dialog.getByTestId('perm-search').fill('users:');
    await expect(dialog.locator('label', { hasText: 'users:read' })).toBeVisible();
    await expect(dialog.locator('label', { hasText: 'roles:read' })).toHaveCount(0);

    // Per-group select-all checks the whole users group; the summary updates.
    await expect(dialog.getByTestId('perm-summary')).toHaveText(/^0 of/);
    await dialog.getByTestId('perm-group-toggle-users').check();
    await expect(dialog.getByTestId('perm-summary')).not.toHaveText(/^0 of/);

    // Cancel — nothing persisted.
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();
    createdRoleName = null;
  });

  // Validation: the Create Role form requires a name and description (zod). With
  // both blank, submitting keeps the dialog open and surfaces field errors; no
  // success toast appears.
  test('create form shows validation errors for empty required fields', async ({
    adminPage,
    page,
  }) => {
    await adminPage.shell.clickNav('Roles');
    await page.waitForURL('**/admin/roles');

    await page.getByRole('button', { name: 'Create Role' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Create New Role')).toBeVisible();

    // Submit empty.
    await dialog.getByRole('button', { name: 'Create Role' }).click();

    await expect(dialog.getByText('Name is required')).toBeVisible();
    await expect(dialog.getByText('Description is required')).toBeVisible();
    // The dialog is still open and no success toast fired.
    await expect(dialog).toBeVisible();
    await expect(page.getByText('Role created successfully')).toHaveCount(0);

    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();
  });

  // Dialog lifecycle: open the Create dialog, then dismiss it via Cancel and via
  // the Escape key; either way it closes and nothing is created.
  test('create dialog can be cancelled and dismissed with Escape', async ({
    adminPage,
    page,
  }) => {
    await adminPage.shell.clickNav('Roles');
    await page.waitForURL('**/admin/roles');

    // Cancel button.
    await page.getByRole('button', { name: 'Create Role' }).click();
    let dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Create New Role')).toBeVisible();
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();

    // Escape key.
    await page.getByRole('button', { name: 'Create Role' }).click();
    dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Create New Role')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
  });
});

/**
 * Role-delete guard rails and global-vs-tenant visibility.
 *
 * The seeded `admin`/`user` roles are GLOBAL (NULL-tenant): visible to the
 * tenant in the LIST but NOT deletable by it (the backend returns 404 for a
 * global role from a non-system tenant — WC-110). A tenant-owned role that
 * still has users assigned cannot be deleted either: the backend returns 409
 * "Cannot delete role with active user assignments". Both surface as a "Failed
 * to delete role" error toast in the UI.
 */
test.describe('Roles delete guards (admin)', () => {
  let createdRoleName: string | null = null;
  let createdEmail: string | null = null;

  test.afterEach(async ({ adminApi }) => {
    // Unassign + delete the probe user first so the role becomes deletable.
    if (createdEmail) {
      const uid = await findUserIdByEmail(adminApi, createdEmail);
      if (uid !== null) await deleteUser(adminApi, uid);
      createdEmail = null;
    }
    if (createdRoleName) {
      const id = await findRoleIdByName(adminApi, createdRoleName);
      if (id !== null) await deleteRole(adminApi, id);
      createdRoleName = null;
    }
  });

  test('global vs tenant-owned role visibility: seeded roles list but are not tenant-deletable', async ({
    adminPage,
    page,
  }) => {
    await adminPage.shell.clickNav('Roles');
    await page.waitForURL('**/admin/roles');

    // The global seeded roles are VISIBLE in the list (read is global).
    const table = page.getByRole('table');
    await expect(table.getByRole('cell', { name: 'admin', exact: true })).toBeVisible();
    await expect(table.getByRole('cell', { name: 'user', exact: true })).toBeVisible();

    // DELETE and EDIT on the global `user` base role are DISABLED for this
    // tenant (WC-222): a global base role (NULL tenant) is not tenant-manageable
    // (only the system tenant may write it), so the row actions are shown
    // disabled with an explanatory tooltip rather than letting the admin open a
    // dialog that would only 404. The seeded role therefore stays.
    const userRow = page.getByRole('row', { name: /^user/ });
    await userRow.getByRole('button').click();
    const deleteItem = page.getByRole('menuitem', { name: 'Delete' });
    await expect(deleteItem).toBeVisible();
    await expect(deleteItem).toHaveAttribute('aria-disabled', 'true');
    await expect(page.getByRole('menuitem', { name: 'Edit' })).toHaveAttribute('aria-disabled', 'true');
    // Close the menu without triggering a delete; the seeded role survives.
    await page.keyboard.press('Escape');
    await expect(table.getByRole('cell', { name: 'user', exact: true })).toBeVisible();
  });

  test('deleting a tenant role with assigned users is blocked (409) and surfaces gracefully', async ({
    adminPage,
    page,
    adminApi,
  }) => {
    const suffix = uniqueSuffix();
    createdRoleName = `e2e-role-409-${suffix}`;
    createdEmail = `e2e-role-409-${suffix}@example.com`;

    // Create the tenant-owned role through the UI.
    await adminPage.shell.clickNav('Roles');
    await page.waitForURL('**/admin/roles');
    await page.getByRole('button', { name: 'Create Role' }).click();
    const createDialog = page.getByRole('dialog');
    await createDialog.getByLabel('Role Name').fill(createdRoleName);
    await createDialog.getByLabel('Description').fill('409 probe');
    await createDialog.getByRole('button', { name: 'Create Role' }).click();
    await expect(page.getByText('Role created successfully')).toBeVisible();

    // Seed a user and assign it to the new role via the API (create ignores the
    // role name and lands on `user`; PATCH resolves names — WC-113). This sets
    // up the "role has active user assignments" precondition for the 409.
    const create = await adminApi.post('/api/v1/users', {
      data: {
        name: 'probe',
        email: createdEmail,
        password: 'e2e-password-123',
        role: 'user',
        tenantId: 1,
      },
    });
    expect(create.ok()).toBeTruthy();
    const uid = await findUserIdByEmail(adminApi, createdEmail);
    expect(uid).not.toBeNull();
    await assignUserRole(adminApi, uid as number, createdRoleName);

    // Now attempt the UI delete: the backend returns 409 and the UI shows an
    // error toast; the role is NOT removed.
    await page.reload();
    await page.waitForURL('**/admin/roles');
    const row = page.getByRole('row', { name: new RegExp(createdRoleName) });
    await row.getByRole('button').click();
    await page.getByRole('menuitem', { name: 'Delete' }).click();
    const deleteDialog = page.getByRole('dialog');
    await deleteDialog.getByRole('button', { name: 'Delete Role' }).click();

    await expect(
      page.getByText(/Cannot delete role with active user assignments|Failed to delete role/i)
    ).toBeVisible();
    await deleteDialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(deleteDialog).toBeHidden();
    await expect(page.getByRole('row', { name: new RegExp(createdRoleName) })).toBeVisible();
  });
});
