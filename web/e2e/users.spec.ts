import { test, expect } from './support/fixtures';
import { uniqueSuffix } from './support/constants';
import { deleteUser, findUserIdByEmail } from './support/api';

/**
 * Users list + CRUD against the live backend.
 *
 * Discipline: the seeded admin/user accounts are READ-ONLY. The create/edit/
 * delete flow operates exclusively on a fresh, uniquely-named user that is
 * removed in afterEach via the API.
 *
 * NOTE: the users list API derives `name` from the email local-part (there is
 * no users.name column) and exposes a camelCase `tenantId` (WC-100), so the
 * table's "Name" column and the Edit dialog pre-fill from the same payload.
 * Rows are still located by email for stability.
 *
 * Role choice: the edit flows switch between the seeded base roles `user` and
 * `admin` (both exist in the demo seed and resolve server-side). The role
 * dropdown is now driven from the live `GET /api/roles` list (WC-121), so it
 * only ever offers roles that actually exist for the tenant — the old static
 * "Moderator" option (which had no backing seed role and 404'd on submit) is
 * gone.
 */
test.describe('Users (admin)', () => {
  let createdEmail: string | null = null;

  test.afterEach(async ({ adminApi }) => {
    if (createdEmail) {
      const id = await findUserIdByEmail(adminApi, createdEmail);
      if (id !== null) {
        await deleteUser(adminApi, id);
      }
      createdEmail = null;
    }
  });

  test('seeded users are listed by email', async ({ adminPage, page }) => {
    await adminPage.shell.clickNav('Users');
    await page.waitForURL('**/admin/users');
    const table = page.getByRole('table');
    await expect(table.getByRole('cell', { name: 'admin@example.com' })).toBeVisible();
    await expect(table.getByRole('cell', { name: 'user@example.com' })).toBeVisible();
  });

  test('create, edit and delete a user', async ({ adminPage, page }) => {
    const suffix = uniqueSuffix();
    createdEmail = `e2e-user-${suffix}@example.com`;

    await adminPage.shell.clickNav('Users');
    await page.waitForURL('**/admin/users');

    // --- Create (role: User) ---
    await page.getByRole('button', { name: 'Create User' }).click();
    const createDialog = page.getByRole('dialog');
    await expect(createDialog.getByText('Create New User')).toBeVisible();

    // WC-168: the form carries only the fields the API reads (email, password,
    // role) — name is server-derived from the email and tenant comes from the
    // caller's tenant context.
    await createDialog.getByLabel('Email').fill(createdEmail);
    await createDialog.getByLabel('Password').fill('e2e-password-123');
    await createDialog.getByRole('combobox').click();
    await page.getByRole('option', { name: 'User' }).click();
    await createDialog.getByRole('button', { name: 'Create User' }).click();

    await expect(page.getByText('User created successfully')).toBeVisible();
    const row = page.getByRole('row', { name: new RegExp(createdEmail) });
    await expect(row).toBeVisible();
    // The new user starts in the `user` role.
    await expect(row.getByRole('cell', { name: 'user', exact: true })).toBeVisible();

    // --- Edit (change role user -> admin) and assert it PERSISTED ---
    await row.getByRole('button').click();
    await page.getByRole('menuitem', { name: 'Edit' }).click();
    const editDialog = page.getByRole('dialog');
    await expect(editDialog.getByRole('heading', { name: 'Edit User' })).toBeVisible();
    // WC-100: Name and Tenant are pre-filled (read-only) from the list payload,
    // so only the field we intend to change (role) needs touching.
    await editDialog.getByRole('combobox', { name: 'Role' }).click();
    await page.getByRole('option', { name: 'Admin' }).click();
    await editDialog.getByRole('button', { name: 'Save Changes' }).click();
    await expect(page.getByText('User updated successfully')).toBeVisible();

    // WC-113: the change must ACTUALLY persist, not just toast. After the list
    // refreshes, the row's Role cell reflects `admin`...
    const adminRow = page.getByRole('row', { name: new RegExp(createdEmail) });
    await expect(adminRow.getByRole('cell', { name: 'admin', exact: true })).toBeVisible();

    // ...and re-opening the Edit dialog shows the new role pre-selected (proves
    // it was read back from the persisted record, not the stale client state).
    await adminRow.getByRole('button').click();
    await page.getByRole('menuitem', { name: 'Edit' }).click();
    const reopened = page.getByRole('dialog');
    await expect(reopened.getByRole('heading', { name: 'Edit User' })).toBeVisible();
    await expect(reopened.getByRole('combobox', { name: 'Role' })).toContainText('Admin');
    await reopened.getByRole('button', { name: 'Cancel' }).click();

    // --- Delete ---
    const updatedRow = page.getByRole('row', { name: new RegExp(createdEmail) });
    await updatedRow.getByRole('button').click();
    await page.getByRole('menuitem', { name: 'Delete' }).click();
    const deleteDialog = page.getByRole('dialog');
    await expect(deleteDialog.getByRole('heading', { name: 'Delete User' })).toBeVisible();
    await deleteDialog.getByRole('button', { name: 'Delete User' }).click();

    await expect(page.getByText('User deleted successfully')).toBeVisible();
    await expect(page.getByRole('row', { name: new RegExp(createdEmail) })).toHaveCount(0);

    createdEmail = null; // already deleted; skip afterEach cleanup
  });

  test('role change persists across a full page reload', async ({ adminPage, page, adminApi }) => {
    const suffix = uniqueSuffix();
    createdEmail = `e2e-role-persist-${suffix}@example.com`;

    await adminPage.shell.clickNav('Users');
    await page.waitForURL('**/admin/users');

    // Seed a user in the `user` role through the API used by the app proxy.
    await page.getByRole('button', { name: 'Create User' }).click();
    const createDialog = page.getByRole('dialog');
    await createDialog.getByLabel('Email').fill(createdEmail);
    await createDialog.getByLabel('Password').fill('e2e-password-123');
    await createDialog.getByRole('combobox').click();
    await page.getByRole('option', { name: 'User' }).click();
    await createDialog.getByRole('button', { name: 'Create User' }).click();
    await expect(page.getByText('User created successfully')).toBeVisible();

    const row = page.getByRole('row', { name: new RegExp(createdEmail) });
    await expect(row).toBeVisible();

    // Change role user -> admin and save.
    await row.getByRole('button').click();
    await page.getByRole('menuitem', { name: 'Edit' }).click();
    const editDialog = page.getByRole('dialog');
    await editDialog.getByRole('combobox', { name: 'Role' }).click();
    await page.getByRole('option', { name: 'Admin' }).click();
    await editDialog.getByRole('button', { name: 'Save Changes' }).click();
    await expect(page.getByText('User updated successfully')).toBeVisible();

    // Hard reload: the table re-fetches from GET /api/users, so a persisted
    // change is the only way the row can still show `admin` (WC-113).
    await page.reload();
    await page.waitForURL('**/admin/users');
    const reloadedRow = page.getByRole('row', { name: new RegExp(createdEmail) });
    await expect(reloadedRow.getByRole('cell', { name: 'admin', exact: true })).toBeVisible();

    // Independent confirmation straight from the API.
    const id = await findUserIdByEmail(adminApi, createdEmail);
    expect(id).not.toBeNull();
    const res = await adminApi.get('/api/users');
    const body = (await res.json()) as { data?: Array<{ email: string; role: string }> };
    const persisted = (body.data ?? []).find((u) => u.email === createdEmail);
    expect(persisted?.role).toBe('admin');
  });

  // WC-121: creating a user with a chosen role must persist THAT role, not
  // silently default to `user`. Previously the create handler read only
  // `role_id` and ignored the submitted role NAME, so a user created as "admin"
  // was created as "user". This test creates with role=Admin and asserts the
  // persisted role is `admin` via BOTH the refreshed table and the list API.
  test('creating a user with a specific role persists that role', async ({
    adminPage,
    page,
    adminApi,
  }) => {
    const suffix = uniqueSuffix();
    createdEmail = `e2e-create-admin-${suffix}@example.com`;

    await adminPage.shell.clickNav('Users');
    await page.waitForURL('**/admin/users');

    await page.getByRole('button', { name: 'Create User' }).click();
    const createDialog = page.getByRole('dialog');
    await expect(createDialog.getByText('Create New User')).toBeVisible();

    await createDialog.getByLabel('Email').fill(createdEmail);
    await createDialog.getByLabel('Password').fill('e2e-password-123');
    // Pick the non-default `admin` role at creation time.
    await createDialog.getByRole('combobox').click();
    await page.getByRole('option', { name: 'Admin' }).click();
    await createDialog.getByRole('button', { name: 'Create User' }).click();

    await expect(page.getByText('User created successfully')).toBeVisible();

    // The refreshed table shows the new user already in the `admin` role — NOT
    // downgraded to `user` (the WC-121 defect). The assertion is exact so a
    // stale "user" cell cannot satisfy it.
    const row = page.getByRole('row', { name: new RegExp(createdEmail) });
    await expect(row).toBeVisible();
    await expect(row.getByRole('cell', { name: 'admin', exact: true })).toBeVisible();

    // Independent confirmation straight from the list API: the persisted role is
    // exactly what was chosen at creation.
    const res = await adminApi.get('/api/users');
    const body = (await res.json()) as { data?: Array<{ email: string; role: string }> };
    const persisted = (body.data ?? []).find((u) => u.email === createdEmail);
    expect(persisted?.role).toBe('admin');
  });
});

test.describe('Edit User pre-fill (WC-100)', () => {
  let createdEmail: string | null = null;

  test.afterEach(async ({ adminApi }) => {
    if (createdEmail) {
      const id = await findUserIdByEmail(adminApi, createdEmail);
      if (id !== null) {
        await deleteUser(adminApi, id);
      }
      createdEmail = null;
    }
  });

  // WC-100: the Edit User modal pre-fills from the row object sourced by the
  // users LIST API (`GET /api/users`). The payload carries `name` (derived from
  // the email local-part, since there is no users.name column) and a camelCase
  // `tenantId`. Name and Tenant are now read-only (WC-113: they are not editable
  // server-side), so the user changes only the role and Saves.
  test('edit modal pre-fills name + tenant and saves after a role change', async ({
    adminPage,
    page,
  }) => {
    const suffix = uniqueSuffix();
    // The probe's email local-part is what the API surfaces as `name`.
    const localPart = `e2e-user-prefill-${suffix}`;
    createdEmail = `${localPart}@example.com`;

    await adminPage.shell.clickNav('Users');
    await page.waitForURL('**/admin/users');

    await page.getByRole('button', { name: 'Create User' }).click();
    const createDialog = page.getByRole('dialog');
    await createDialog.getByLabel('Email').fill(createdEmail);
    await createDialog.getByLabel('Password').fill('e2e-password-123');
    await createDialog.getByRole('combobox').click();
    await page.getByRole('option', { name: 'User' }).click();
    await createDialog.getByRole('button', { name: 'Create User' }).click();
    await expect(page.getByText('User created successfully')).toBeVisible();

    const row = page.getByRole('row', { name: new RegExp(createdEmail) });
    await expect(row).toBeVisible();
    await row.getByRole('button').click();
    await page.getByRole('menuitem', { name: 'Edit' }).click();
    const editDialog = page.getByRole('dialog');
    await expect(editDialog.getByRole('heading', { name: 'Edit User' })).toBeVisible();

    // The pre-fill: Name (derived from the email local-part) and Tenant are
    // populated from the list payload — shown read-only, no manual re-entry.
    await expect(editDialog.getByLabel('Name')).toHaveValue(localPart);
    await expect(editDialog.getByLabel('Tenant')).toHaveValue('1');

    // Change the role (user -> admin) and Save: it must succeed and persist.
    await editDialog.getByRole('combobox', { name: 'Role' }).click();
    await page.getByRole('option', { name: 'Admin' }).click();
    await editDialog.getByRole('button', { name: 'Save Changes' }).click();
    await expect(page.getByText('User updated successfully')).toBeVisible();

    const updatedRow = page.getByRole('row', { name: new RegExp(createdEmail) });
    await expect(updatedRow.getByRole('cell', { name: 'admin', exact: true })).toBeVisible();
  });
});

/**
 * Create User form: client-side validation and dialog lifecycle.
 *
 * The create form is zod-validated (valid email, password >= 8, role required —
 * WC-168 removed the dead Name/Tenant inputs the API never read). These tests
 * assert the field-level errors surface and that no user is created, so they
 * never touch the database.
 */
test.describe('Create User validation + dialog (admin)', () => {
  test('shows validation errors and does not submit when fields are empty/invalid', async ({
    adminPage,
    page,
  }) => {
    await adminPage.shell.clickNav('Users');
    await page.waitForURL('**/admin/users');

    await page.getByRole('button', { name: 'Create User' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Create New User')).toBeVisible();

    // Submit fully empty: email/password/role errors appear.
    await dialog.getByRole('button', { name: 'Create User' }).click();
    await expect(dialog.getByText('Invalid email address')).toBeVisible();
    await expect(dialog.getByText('Password must be at least 8 characters')).toBeVisible();
    await expect(dialog.getByText('Role is required')).toBeVisible();

    // Invalid email + too-short password keep their field errors after another
    // submit attempt.
    await dialog.getByLabel('Email').fill('not-an-email');
    await dialog.getByLabel('Password').fill('short');
    await dialog.getByRole('button', { name: 'Create User' }).click();
    await expect(dialog.getByText('Invalid email address')).toBeVisible();
    await expect(dialog.getByText('Password must be at least 8 characters')).toBeVisible();

    // Nothing was created.
    await expect(page.getByText('User created successfully')).toHaveCount(0);
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();
  });

  test('create dialog closes on Escape without creating a user', async ({
    adminPage,
    page,
  }) => {
    await adminPage.shell.clickNav('Users');
    await page.waitForURL('**/admin/users');

    await page.getByRole('button', { name: 'Create User' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Create New User')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
  });
});

/**
 * WC-121: the phantom "Moderator" dropdown option is GONE.
 *
 * The role dropdown used to offer a static third option, "Moderator", that had
 * NO backing role in the seed; once the backend began validating role NAMES
 * (WC-113) selecting it 404'd. WC-121 drives the dropdown from the live
 * `GET /api/roles` list, so it now only offers roles that actually exist for the
 * tenant (`User` and `Admin` in the demo seed). This was a `test.fixme` for the
 * known bug; it is now a real, passing assertion that the dropdown contains the
 * seeded roles and NOT "Moderator".
 */
test.describe('Users role dropdown is driven from real roles (WC-121)', () => {
  test('the create dialog offers only the seeded roles and no phantom Moderator', async ({
    adminPage,
    page,
  }) => {
    await adminPage.shell.clickNav('Users');
    await page.waitForURL('**/admin/users');

    await page.getByRole('button', { name: 'Create User' }).click();
    const createDialog = page.getByRole('dialog');
    await expect(createDialog.getByText('Create New User')).toBeVisible();

    await createDialog.getByRole('combobox').click();

    // The real seeded roles are offered...
    await expect(page.getByRole('option', { name: 'User' })).toBeVisible();
    await expect(page.getByRole('option', { name: 'Admin' })).toBeVisible();
    // ...and the phantom "Moderator" option is no longer present.
    await expect(page.getByRole('option', { name: 'Moderator' })).toHaveCount(0);

    await page.keyboard.press('Escape'); // close the listbox
    await createDialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(createDialog).toBeHidden();
  });

  test('the edit dialog offers only the seeded roles and no phantom Moderator', async ({
    adminPage,
    page,
    adminApi,
  }) => {
    // The edit dialog needs an existing row; open it on a throwaway user and
    // clean up via the API afterwards.
    const suffix = uniqueSuffix();
    const probeEmail = `e2e-dropdown-${suffix}@example.com`;

    await adminPage.shell.clickNav('Users');
    await page.waitForURL('**/admin/users');

    await page.getByRole('button', { name: 'Create User' }).click();
    const createDialog = page.getByRole('dialog');
    await createDialog.getByLabel('Email').fill(probeEmail);
    await createDialog.getByLabel('Password').fill('e2e-password-123');
    await createDialog.getByRole('combobox').click();
    await page.getByRole('option', { name: 'User' }).click();
    await createDialog.getByRole('button', { name: 'Create User' }).click();
    await expect(page.getByText('User created successfully')).toBeVisible();

    try {
      const row = page.getByRole('row', { name: new RegExp(probeEmail) });
      await row.getByRole('button').click();
      await page.getByRole('menuitem', { name: 'Edit' }).click();
      const editDialog = page.getByRole('dialog');
      await expect(editDialog.getByRole('heading', { name: 'Edit User' })).toBeVisible();

      await editDialog.getByRole('combobox', { name: 'Role' }).click();
      await expect(page.getByRole('option', { name: 'User' })).toBeVisible();
      await expect(page.getByRole('option', { name: 'Admin' })).toBeVisible();
      await expect(page.getByRole('option', { name: 'Moderator' })).toHaveCount(0);
    } finally {
      const id = await findUserIdByEmail(adminApi, probeEmail);
      if (id !== null) await deleteUser(adminApi, id);
    }
  });
});
