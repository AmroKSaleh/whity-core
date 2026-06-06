import { test, expect } from './support/fixtures';

/**
 * Organizational Units + Tenants smoke flows against the live backend.
 *
 * The full OU CRUD + hierarchy + roles/members coverage lives in
 * `ous-hub.spec.ts` (WC-44 replaced the flat DataTable with the OU Management
 * Hub). This file keeps a single OU page-renders smoke test plus the Tenants
 * form/guard flows.
 *
 * Shared-DB discipline:
 *  - Tenants are NEVER created persistently here (the backend forbids deleting
 *    any tenant other than the caller's own, and the only seeded tenant is
 *    occupied), so we only assert the list renders and that the delete guard
 *    surfaces an error gracefully. Tenant 0/1 and seeded data are never removed.
 */

test.describe('Organizational Units (admin)', () => {
  test('OU hub page renders with the Tree | Graph toggle and Create OU action', async ({
    adminPage,
    page,
  }) => {
    await adminPage.shell.clickNav('Organizational Units');
    await page.waitForURL('**/admin/ous');
    await expect(
      page.getByRole('heading', { name: 'Organizational Units' }).first()
    ).toBeVisible();
    await expect(page.getByRole('group', { name: 'View mode' })).toBeVisible();
    await expect(page.getByRole('button', { name: /Create (the first )?OU/ })).toBeVisible();
  });
});

/**
 * Tenants flows.
 *
 * Shared-DB discipline is especially strict here: the tenant-isolation
 * middleware lets the admin (tenant 1) READ the tenant list but rejects any
 * cross-tenant WRITE (PATCH/DELETE on a tenant other than its own → 403), and
 * a freshly created tenant cannot be cleaned up by this admin afterward.
 * Therefore these tests NEVER persist a new tenant and NEVER mutate the seeded
 * Default Tenant — they exercise the create/edit FORMS (validation, auto-slug,
 * pre-fill) and cancel before any write, plus the delete guard which is
 * expected to fail server-side and leave the data untouched.
 */
test.describe('Tenants (admin)', () => {
  test('tenants list shows the Default Tenant', async ({ adminPage, page }) => {
    await adminPage.shell.clickNav('Tenants');
    await page.waitForURL('**/admin/tenants');
    await expect(page.getByRole('heading', { name: 'Tenants' })).toBeVisible();
    await expect(
      page.getByRole('cell', { name: 'Default Tenant', exact: true })
    ).toBeVisible();
  });

  test('create form auto-generates a slug from the name and validates a bad slug', async ({
    adminPage,
    page,
  }) => {
    await adminPage.shell.clickNav('Tenants');
    await page.waitForURL('**/admin/tenants');

    await page.getByRole('button', { name: 'Create Tenant' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Create New Tenant')).toBeVisible();

    // Typing the Name auto-fills the Slug (lowercased, hyphenated).
    await dialog.getByLabel('Name').fill('Acme Widgets Co');
    await expect(dialog.getByLabel('Slug')).toHaveValue('acme-widgets-co');

    // Overwrite with an invalid slug (spaces/uppercase) and submit: the zod
    // regex rejects it with a field error and nothing is created.
    await dialog.getByLabel('Slug').fill('Invalid Slug!');
    await dialog.getByRole('button', { name: 'Create Tenant' }).click();
    await expect(
      dialog.getByText('Slug must contain only lowercase letters, numbers, and hyphens')
    ).toBeVisible();
    await expect(page.getByText('Tenant created successfully')).toHaveCount(0);

    // Cancel without persisting — we deliberately never create a real tenant.
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();
  });

  test('edit dialog pre-fills the selected tenant and cancels without mutating it', async ({
    adminPage,
    page,
  }) => {
    await adminPage.shell.clickNav('Tenants');
    await page.waitForURL('**/admin/tenants');

    const row = page.getByRole('row', { name: /Default Tenant/ });
    await row.getByRole('button').click();
    await page.getByRole('menuitem', { name: 'Edit' }).click();

    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('heading', { name: 'Edit Tenant' })).toBeVisible();
    // The Name field is pre-filled from the selected row.
    await expect(dialog.getByLabel('Name')).toHaveValue('Default Tenant');

    // Cancel without saving — the seeded tenant must remain exactly as it was.
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();
    await expect(
      page.getByRole('cell', { name: 'Default Tenant', exact: true })
    ).toBeVisible();
  });

  test('attempting to delete the occupied Default Tenant surfaces an error gracefully', async ({
    adminPage,
    page,
  }) => {
    await adminPage.shell.clickNav('Tenants');
    await page.waitForURL('**/admin/tenants');

    const row = page.getByRole('row', { name: /Default Tenant/ });
    await row.getByRole('button').click();
    await page.getByRole('menuitem', { name: 'Delete' }).click();

    const deleteDialog = page.getByRole('dialog');
    await expect(deleteDialog.getByRole('heading', { name: 'Delete Tenant' })).toBeVisible();

    // WC-122: the modal's "this tenant has N associated users" warning now
    // renders. The list API previously returned the count as `usercount` (the
    // database folds the unquoted `userCount` SQL alias to lowercase) while the
    // modal reads `tenant.userCount` (camelCase), so the warning never appeared.
    // The handler now shapes the row to the camelCase public contract, so the
    // occupied Default Tenant surfaces its associated-user count (>= 1: it is
    // seeded with the admin user, so the delete guard below also rejects it).
    await expect(
      deleteDialog.getByText(/This tenant has \d+ associated users?\. Deleting it may impact those users\./)
    ).toBeVisible();

    await deleteDialog.getByRole('button', { name: 'Delete Tenant' }).click();

    // Backend guard: tenant 1 is occupied (and is the caller's own tenant) so
    // the delete is rejected. The UI must show an error toast; the delete fails
    // so the dialog stays open. We never actually remove the seeded tenant.
    await expect(page.getByText(/Failed to delete tenant|forbidden/i)).toBeVisible();

    // Close the (still-open) dialog and confirm the tenant is still listed.
    await deleteDialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(deleteDialog).toBeHidden();
    await expect(
      page.getByRole('cell', { name: 'Default Tenant', exact: true })
    ).toBeVisible();
  });
});
