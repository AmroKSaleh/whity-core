import { test, expect } from './support/fixtures';
import { uniqueSuffix } from './support/constants';
import { deleteOu, findOuIdByName } from './support/api';

/**
 * Organizational Units + Tenants flows against the live backend.
 *
 * Shared-DB discipline:
 *  - OUs are created with unique names and cleaned up via the API in afterEach.
 *  - Tenants are NEVER created persistently here (the backend forbids deleting
 *    any tenant other than the caller's own, and the only seeded tenant is
 *    occupied), so we only assert the list renders and that the delete guard
 *    surfaces an error gracefully. Tenant 0/1 and seeded data are never removed.
 */

test.describe('Organizational Units (admin)', () => {
  let createdOuName: string | null = null;

  test.afterEach(async ({ adminApi }) => {
    if (createdOuName) {
      const id = await findOuIdByName(adminApi, createdOuName);
      if (id !== null) {
        // Best-effort cleanup: the backend OU delete returns 204, which the
        // proxy now forwards as a clean 204 (WC-101 fix).
        await deleteOu(adminApi, id);
      }
      createdOuName = null;
    }
  });

  test('OUs page renders', async ({ adminPage, page }) => {
    await adminPage.shell.clickNav('Organizational Units');
    await page.waitForURL('**/admin/ous');
    await expect(
      page.getByRole('heading', { name: 'Organizational Units' }).first()
    ).toBeVisible();
    // Either an empty-state prompt or a populated table is acceptable.
    await expect(page.getByRole('button', { name: /Create (the first )?OU/ })).toBeVisible();
  });

  test('create an organizational unit and see it listed', async ({ adminPage, page }) => {
    createdOuName = `e2e-ou-${uniqueSuffix()}`;

    await adminPage.shell.clickNav('Organizational Units');
    await page.waitForURL('**/admin/ous');

    await page.getByRole('button', { name: /Create (the first )?OU/ }).first().click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Create Organizational Unit')).toBeVisible();

    await dialog.getByPlaceholder('e.g., Engineering').fill(createdOuName);
    await dialog.getByPlaceholder('Optional description for this OU').fill('Created by E2E');
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();

    await expect(page.getByText('Organizational unit created successfully')).toBeVisible();
    // The OU name and its auto-derived slug are identical, so the name text
    // appears in two cells; assert the row exists rather than a single cell.
    await expect(page.getByRole('row', { name: new RegExp(createdOuName) })).toBeVisible();
  });

  // WC-101: the Next.js proxy route handler (app/api/[...path]/route.ts)
  // previously built `new Response(body, { status })` while forwarding the
  // backend response. The OU DELETE endpoint returns HTTP 204 No Content;
  // constructing a Response with a (non-null) body and status 204 threw
  // "Invalid response status code 204", so the proxied call returned 500 and
  // the UI showed an error toast. The proxy now forwards null-body statuses
  // (204/205/304) without a body, so the delete reports success.
  test('delete an organizational unit from the UI', async ({ adminPage, page }) => {
    createdOuName = `e2e-ou-del-${uniqueSuffix()}`;

    await adminPage.shell.clickNav('Organizational Units');
    await page.waitForURL('**/admin/ous');

    await page.getByRole('button', { name: /Create (the first )?OU/ }).first().click();
    const dialog = page.getByRole('dialog');
    await dialog.getByPlaceholder('e.g., Engineering').fill(createdOuName);
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(page.getByText('Organizational unit created successfully')).toBeVisible();

    const row = page.getByRole('row', { name: new RegExp(createdOuName) });
    await row.getByRole('button').click();
    await page.getByRole('menuitem', { name: 'Delete' }).click();
    const deleteDialog = page.getByRole('dialog');
    await deleteDialog.getByRole('button', { name: /Delete/ }).click();

    // Expected once the proxy is fixed:
    await expect(page.getByText('Organizational unit deleted successfully')).toBeVisible();
    await expect(page.getByRole('row', { name: new RegExp(createdOuName) })).toHaveCount(0);
  });
});

test.describe('Tenants (admin)', () => {
  test('tenants list shows the Default Tenant', async ({ adminPage, page }) => {
    await adminPage.shell.clickNav('Tenants');
    await page.waitForURL('**/admin/tenants');
    await expect(page.getByRole('heading', { name: 'Tenants' })).toBeVisible();
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
    await deleteDialog.getByRole('button', { name: 'Delete Tenant' }).click();

    // Backend guard: tenant 1 is occupied (2 users) -> 409. The UI must show an
    // error toast; the delete fails so the dialog stays open. We never actually
    // remove the seeded tenant.
    await expect(page.getByText(/Failed to delete tenant/i)).toBeVisible();

    // Close the (still-open) dialog and confirm the tenant is still listed.
    await deleteDialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(deleteDialog).toBeHidden();
    await expect(
      page.getByRole('cell', { name: 'Default Tenant', exact: true })
    ).toBeVisible();
  });
});
