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

    // --- Create ---
    await page.getByRole('button', { name: 'Create User' }).click();
    const createDialog = page.getByRole('dialog');
    await expect(createDialog.getByText('Create New User')).toBeVisible();

    await createDialog.getByLabel('Name').fill(`E2E User ${suffix}`);
    await createDialog.getByLabel('Email').fill(createdEmail);
    await createDialog.getByLabel('Password').fill('e2e-password-123');
    await createDialog.getByRole('combobox').click();
    await page.getByRole('option', { name: 'User' }).click();
    await createDialog.getByLabel('Tenant').fill('1');
    await createDialog.getByRole('button', { name: 'Create User' }).click();

    await expect(page.getByText('User created successfully')).toBeVisible();
    const row = page.getByRole('row', { name: new RegExp(createdEmail) });
    await expect(row).toBeVisible();

    // --- Edit (change role to moderator) ---
    await row.getByRole('button').click();
    await page.getByRole('menuitem', { name: 'Edit' }).click();
    const editDialog = page.getByRole('dialog');
    await expect(editDialog.getByRole('heading', { name: 'Edit User' })).toBeVisible();
    // WC-100: Name and Tenant are pre-filled from the list payload, so only the
    // field we intend to change (role) needs touching; Save still validates.
    await editDialog.getByRole('combobox').click();
    await page.getByRole('option', { name: 'Moderator' }).click();
    await editDialog.getByRole('button', { name: 'Save Changes' }).click();
    await expect(page.getByText('User updated successfully')).toBeVisible();

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
  // users LIST API (`GET /api/users`). The payload now carries `name` (derived
  // from the email local-part, since there is no users.name column) and a
  // camelCase `tenantId`, so the form's required Name and Tenant fields are
  // populated. A user can then change a single field and Save without having to
  // re-type the others — previously both rendered blank and Save failed the
  // zod required-field validation.
  test('edit modal pre-fills name + tenant and saves after one change', async ({
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
    await createDialog.getByLabel('Name').fill('Prefill Probe');
    await createDialog.getByLabel('Email').fill(createdEmail);
    await createDialog.getByLabel('Password').fill('e2e-password-123');
    await createDialog.getByRole('combobox').click();
    await page.getByRole('option', { name: 'User' }).click();
    await createDialog.getByLabel('Tenant').fill('1');
    await createDialog.getByRole('button', { name: 'Create User' }).click();
    await expect(page.getByText('User created successfully')).toBeVisible();

    const row = page.getByRole('row', { name: new RegExp(createdEmail) });
    await expect(row).toBeVisible();
    await row.getByRole('button').click();
    await page.getByRole('menuitem', { name: 'Edit' }).click();
    const editDialog = page.getByRole('dialog');
    await expect(editDialog.getByRole('heading', { name: 'Edit User' })).toBeVisible();

    // The pre-fill: Name (derived from the email local-part) and Tenant are
    // populated from the list payload — no manual re-entry needed.
    await expect(editDialog.getByLabel('Name')).toHaveValue(localPart);
    await expect(editDialog.getByLabel('Tenant')).toHaveValue('1');

    // Change exactly one field (role) and Save: it must succeed because the
    // other required fields were pre-filled.
    await editDialog.getByRole('combobox').click();
    await page.getByRole('option', { name: 'Moderator' }).click();
    await editDialog.getByRole('button', { name: 'Save Changes' }).click();
    await expect(page.getByText('User updated successfully')).toBeVisible();
  });
});
