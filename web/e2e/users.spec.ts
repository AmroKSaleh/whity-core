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
 * NOTE: the users list API returns no `name` field, so the table's "Name"
 * column renders "-" and rows are identified by email instead.
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
    // NOTE (app bug #3): the edit modal does NOT pre-fill Name / Tenant because
    // the users LIST API returns neither `name` nor `tenantId` (it returns
    // `tenant_id`). Both are required by the form's zod schema, so they must be
    // re-entered for the PATCH to validate. We do that here to exercise the
    // edit happy-path; the missing pre-fill is asserted separately in the
    // known-bug spec below.
    await editDialog.getByLabel('Name').fill(`E2E User ${suffix} edited`);
    await editDialog.getByLabel('Tenant').fill('1');
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

test.describe('Known app bugs (users)', () => {
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

  // BUG #3: the Edit User modal pre-fills from the row object, but the users
  // LIST API (`GET /api/users`) returns neither `name` nor `tenantId` (it
  // returns `tenant_id`). The page's `User` type expects `name`/`tenantId`, so
  // both are `undefined`; the edit form leaves Name and Tenant blank. Because
  // the zod schema marks both required, an unmodified Save fails validation
  // ("Invalid input: expected string, received undefined") — i.e. you cannot
  // edit a user without re-typing fields that should have been pre-filled.
  test.fixme('edit modal should pre-fill the current name and tenant', async ({
    adminPage,
    page,
  }) => {
    createdEmail = `e2e-user-prefill-${uniqueSuffix()}@example.com`;

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
    await row.getByRole('button').click();
    await page.getByRole('menuitem', { name: 'Edit' }).click();
    const editDialog = page.getByRole('dialog');

    // Expected once fixed: the name we created with is shown again.
    await expect(editDialog.getByLabel('Name')).toHaveValue('Prefill Probe');
    await expect(editDialog.getByLabel('Tenant')).toHaveValue('1');
  });
});
