import { test, expect } from './support/fixtures';
import { ADMIN, uniqueSuffix } from './support/constants';
import { toastWithText } from './support/pages';
import { createAuthedApi, deleteGreetingsMatching } from './support/api';

/**
 * WC-173 role matrix — HelloWorld plugin screen (/admin/x/hello-greetings).
 *
 * LIVE-VERIFIED access map:
 *   /api/frontend/features: admin -> includes hello-greetings; user -> [];
 *                           delegate -> hello-greetings ONLY (delegated
 *                           hello:view honored by the feature filter)
 *   GET  /api/hello/greetings: admin 200, user 403, delegate 200
 *   POST /api/hello/greetings: delegate 403 (hello:manage is NOT delegated)
 *
 * NOTE: the dev stack also carries an extra Announcements plugin that CI will
 * not have — nothing here asserts on announcements, only on hello-greetings.
 *
 * DATA HYGIENE: greetings created here carry a uniqueSuffix marker; cleanup
 * deletes only marker-matching rows, tolerating any pre-existing greetings on
 * the shared dev database.
 */

// Shared with the cleanup hook so a mid-test failure still gets cleaned up.
let greetingMarker: string | null = null;

test.describe('Plugin screen: HelloWorld greetings (role matrix)', () => {
  test.afterEach(async ({ baseURL, role }) => {
    void role;
    if (greetingMarker === null || baseURL === undefined) {
      return;
    }
    const api = await createAuthedApi(baseURL, ADMIN);
    await deleteGreetingsMatching(api, greetingMarker);
    await api.dispose();
    greetingMarker = null;
  });

  test('the nav link is shown to every role; the screen is permission-gated', async ({
    roleSession,
    role,
    page,
  }) => {
    // CURRENT BEHAVIOR: /api/navigation is NOT permission-filtered, so the
    // plugin's "Greetings" entry is visible even to roles that cannot use the
    // feature — gating happens at the screen (permission-filtered feature
    // list) and data (route RBAC) layers instead.
    const link = roleSession.shell.navLink('Greetings');
    await expect(link).toBeVisible();

    if (role === 'user') {
      await link.click();
      await page.waitForURL('**/admin/x/hello-greetings');
      // The user's feature list is empty, so the host page cannot resolve the
      // feature id and renders the unavailable card (no data request fires).
      await expect(
        page.getByRole('heading', { name: 'Feature unavailable' })
      ).toBeVisible();
      await expect(
        page.getByRole('heading', { name: 'Not available' })
      ).toBeVisible();
      await expect(
        page.getByText(
          "The feature 'hello-greetings' does not exist or you do not have permission to use it."
        )
      ).toBeVisible();
      return;
    }

    // admin (role grant) and delegate (delegated hello:view) get the screen.
    const listResponse = page.waitForResponse(
      (res) =>
        res.url().includes('/api/hello/greetings') &&
        res.request().method() === 'GET'
    );
    await link.click();
    await page.waitForURL('**/admin/x/hello-greetings');
    expect((await listResponse).status()).toBe(200);

    await expect(page.getByRole('heading', { name: 'Greetings' })).toBeVisible();
    await expect(
      page.getByRole('heading', { name: 'Feature unavailable' })
    ).toHaveCount(0);
    await expect(
      page.getByRole('heading', { name: 'Access denied' })
    ).toHaveCount(0);
  });

  test('admin has full schema-driven CRUD on greetings', async ({
    roleSession,
    role,
    page,
  }) => {
    test.skip(
      role !== 'admin',
      'Admin-only lifecycle; the user/delegate surfaces are pinned in the other tests.'
    );

    greetingMarker = `matrix-greeting-${uniqueSuffix()}`;
    const message = `Hello from ${greetingMarker}`;
    const editedMessage = `${message} (edited)`;

    await roleSession.shell.clickNav('Greetings');
    await page.waitForURL('**/admin/x/hello-greetings');
    await expect(page.getByRole('heading', { name: 'Greetings' })).toBeVisible();

    // Create: the dialog is derived from the plugin's OpenAPI schema and
    // exposes the single required Message field.
    await page.getByRole('button', { name: 'Create', exact: true }).click();
    const createDialog = page.getByRole('dialog');
    await expect(createDialog.getByText('Create Greetings')).toBeVisible();
    await createDialog.locator('#crud-field-message').fill(message);
    await createDialog.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(toastWithText(page, 'Record created successfully')).toBeVisible();

    // The row lifecycle is asserted by its unique message text, so any
    // pre-existing greetings on a shared database are tolerated.
    const row = page.getByRole('row').filter({ hasText: message });
    await expect(row).toBeVisible();

    // Edit: the dialog arrives pre-filled with the row's current data.
    await row.getByRole('button', { name: 'Row actions' }).click();
    await page.getByRole('menuitem', { name: 'Edit' }).click();
    const editDialog = page.getByRole('dialog');
    await expect(editDialog.getByText('Edit Greetings')).toBeVisible();
    await expect(editDialog.locator('#crud-field-message')).toHaveValue(message);
    await editDialog.locator('#crud-field-message').fill(editedMessage);
    await editDialog.getByRole('button', { name: 'Save changes' }).click();
    await expect(toastWithText(page, 'Record updated successfully')).toBeVisible();

    const editedRow = page.getByRole('row').filter({ hasText: editedMessage });
    await expect(editedRow).toBeVisible();

    // Delete: the confirmation dialog identifies the row by its title field
    // (the message text).
    await editedRow.getByRole('button', { name: 'Row actions' }).click();
    await page.getByRole('menuitem', { name: 'Delete' }).click();
    const deleteDialog = page.getByRole('dialog');
    await expect(deleteDialog.getByText('Delete Greetings')).toBeVisible();
    await expect(deleteDialog.getByText(editedMessage)).toBeVisible();
    await deleteDialog.getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(toastWithText(page, 'Record deleted successfully')).toBeVisible();

    await expect(
      page.getByRole('row').filter({ hasText: greetingMarker })
    ).toHaveCount(0);
  });

  test('delegate sees the create affordance but the write is denied server-side', async ({
    roleSession,
    role,
    page,
  }) => {
    test.skip(
      role !== 'delegate',
      'Pins the delegate-specific capability gap; other roles are covered above.'
    );

    greetingMarker = `matrix-delegate-denied-${uniqueSuffix()}`;
    const message = `Denied write ${greetingMarker}`;

    await roleSession.shell.clickNav('Greetings');
    await page.waitForURL('**/admin/x/hello-greetings');
    await expect(page.getByRole('heading', { name: 'Greetings' })).toBeVisible();

    // CURRENT-BEHAVIOR PIN (known UX gap): the schema-driven CRUD screen
    // derives its capabilities from the OpenAPI SPEC, not from the caller's
    // permissions, so the Create button is visible even though the delegate
    // holds only hello:view (hello:manage was never delegated).
    const createButton = page.getByRole('button', { name: 'Create', exact: true });
    await expect(createButton).toBeVisible();

    await createButton.click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Create Greetings')).toBeVisible();
    await dialog.locator('#crud-field-message').fill(message);

    const createResponse = page.waitForResponse(
      (res) =>
        res.url().includes('/api/hello/greetings') &&
        res.request().method() === 'POST'
    );
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();
    expect((await createResponse).status()).toBe(403);

    // The backend rejection surfaces as an error toast with the server's
    // message; the dialog stays open and no row is ever created.
    await expect(toastWithText(page, 'Insufficient permissions')).toBeVisible();
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(
      page.getByRole('row').filter({ hasText: greetingMarker })
    ).toHaveCount(0);
  });
});
