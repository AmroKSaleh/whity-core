import { test, expect } from './support/fixtures';
import { ADMIN, uniqueSuffix } from './support/constants';
import { toastWithText } from './support/pages';
import { createAuthedApi, deleteGreetingsMatching } from './support/api';

/**
 * WC-173 role matrix — HelloWorld plugin screen (/admin/x/hello-greetings).
 *
 * LIVE-VERIFIED access map (WC-175):
 *   /api/navigation (#191): the "Greetings" nav link is RBAC-filtered on
 *                           requiredPermission hello:view — shown to admin
 *                           (role grant) and delegate (delegated hello:view),
 *                           HIDDEN for the plain user.
 *   /api/frontend/features: admin -> includes hello-greetings; user -> [];
 *                           delegate -> hello-greetings ONLY (delegated
 *                           hello:view honored by the feature filter)
 *   GET  /api/hello/greetings: admin 200, user 403, delegate 200
 *   feature.capabilities (#199): the schema-driven CRUD screen now hides the
 *                           Create/Edit/Delete controls a caller lacks — the
 *                           delegate holds hello:view but NOT hello:manage, so
 *                           it sees a READ-ONLY screen (no write controls).
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

  test('the nav link follows hello:view; the screen loads for the holders', async ({
    roleSession,
    role,
    page,
  }) => {
    // NEW BEHAVIOR (WC-175 #191): /api/navigation IS permission-filtered, so
    // the plugin's "Greetings" entry is shown only to callers that hold
    // hello:view — admin (role grant) and delegate (delegated). The plain user
    // does not hold it, so the link is filtered out server-side.
    if (role === 'user') {
      await expect(roleSession.shell.navLink('Greetings')).toHaveCount(0);
      return;
    }

    // admin (role grant) and delegate (delegated hello:view) get the screen.
    const link = roleSession.shell.navLink('Greetings');
    await expect(link).toBeVisible();
    const listResponse = page.waitForResponse(
      (res) =>
        res.url().includes('/api/v1/hello/greetings') &&
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

  test('delegate has read-only access: no write controls on the greetings screen', async ({
    roleSession,
    role,
    page,
    baseURL,
  }) => {
    test.skip(
      role !== 'delegate',
      'Pins the delegate read-only surface; other roles are covered above.'
    );
    if (baseURL === undefined) {
      throw new Error('baseURL is required to seed the delegate read-only row');
    }

    // SEED a real row so the per-row write-control absence is NON-VACUOUS: the
    // greetings list is shared dev-DB state and can be EMPTY when this runs, in
    // which case "no Row actions button" would pass with zero rows regardless of
    // gating. We POST one greeting as the admin (who holds hello:manage) into
    // the shared tenant the delegate also belongs to, so the delegate's GET sees
    // it. The marker is stamped on the module-level `greetingMarker` so the
    // afterEach cleanup deletes exactly this row even if the test fails midway.
    greetingMarker = `matrix-greeting-${uniqueSuffix()}`;
    const message = `Hello from ${greetingMarker}`;
    const seedApi = await createAuthedApi(baseURL, ADMIN);
    try {
      const created = await seedApi.post('/api/v1/hello/greetings', {
        data: { message },
      });
      expect(
        created.status(),
        'seeding a greeting as admin should return 201'
      ).toBe(201);
    } finally {
      await seedApi.dispose();
    }

    const listResponse = page.waitForResponse(
      (res) =>
        res.url().includes('/api/v1/hello/greetings') &&
        res.request().method() === 'GET'
    );
    await roleSession.shell.clickNav('Greetings');
    await page.waitForURL('**/admin/x/hello-greetings');
    expect((await listResponse).status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'Greetings' })).toBeVisible();

    // The seeded row is present in the delegate's read-only table — so the
    // per-row write-control assertion below is tested against a REAL row.
    const seededRow = page.getByRole('row').filter({ hasText: message });
    await expect(seededRow).toBeVisible();

    // FIXED BEHAVIOR (WC-175 #199): the schema-driven CRUD screen now derives
    // its write capabilities from the server-provided feature.capabilities, not
    // the OpenAPI spec. The delegate holds hello:view but NOT hello:manage, so
    // the screen is read-only — the Create button is gone, and the seeded row
    // exposes no "Row actions" menu (Edit/Delete are not rendered for a
    // read-only caller, even though a real row is present).
    await expect(
      page.getByRole('button', { name: 'Create', exact: true })
    ).toHaveCount(0);
    await expect(
      page.getByRole('button', { name: 'Row actions' })
    ).toHaveCount(0);
  });
});
