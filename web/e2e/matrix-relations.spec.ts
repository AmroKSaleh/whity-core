import { test, expect } from './support/fixtures';
import { ADMIN, uniqueSuffix } from './support/constants';
import { toastWithText } from './support/pages';
import {
  createAuthedApi,
  deletePerson,
  findPersonIdByName,
} from './support/api';

/**
 * WC-173 role matrix — Family Relations admin area (/admin/relations).
 *
 * LIVE-VERIFIED access map for GET /api/persons and GET /api/relations (both
 * gated on relations:read):
 *   admin    -> 200 (role grant, core migration)
 *   user     -> 403 (Access denied card)
 *   delegate -> 200 — THE flagship delegated-read pin: the active
 *               relations:read delegation turns the plain user's 403 into a
 *               working read surface.
 */

// Shared with the cleanup hook so a mid-test failure still gets cleaned up.
let createdPersonName: string | null = null;

test.describe('Family Relations (role matrix)', () => {
  test.afterEach(async ({ baseURL, role }) => {
    if (role !== 'admin' || createdPersonName === null || baseURL === undefined) {
      return;
    }
    const api = await createAuthedApi(baseURL, ADMIN);
    const personId = await findPersonIdByName(api, createdPersonName);
    if (personId !== null) {
      await deletePerson(api, personId);
    }
    await api.dispose();
    createdPersonName = null;
  });

  test('page surface follows the role access map', async ({
    roleSession,
    role,
    page,
  }) => {
    const personsResponse = page.waitForResponse(
      (res) =>
        res.url().includes('/api/persons') && res.request().method() === 'GET'
    );
    // /api/relations needs an end-of-path match so it cannot collide with the
    // page's parallel /api/relationship-types call.
    const relationsResponse = page.waitForResponse(
      (res) =>
        /\/api\/relations(\?.*)?$/.test(res.url()) &&
        res.request().method() === 'GET'
    );
    await roleSession.shell.clickNav('Family Relations');
    await page.waitForURL('**/admin/relations');

    const expectedStatus = role === 'user' ? 403 : 200;
    expect((await personsResponse).status()).toBe(expectedStatus);
    expect((await relationsResponse).status()).toBe(expectedStatus);

    await expect(
      page.getByRole('heading', { name: 'Family Relations' })
    ).toBeVisible();

    if (role === 'user') {
      await expect(
        page.getByRole('heading', { name: 'Access denied' })
      ).toBeVisible();
      await expect(
        page.getByText(
          'You need the relations:read permission to view family relations.'
        )
      ).toBeVisible();
      await expect(
        page.getByRole('button', { name: 'Add relative', exact: true })
      ).toHaveCount(0);
      return;
    }

    // admin AND delegate get the full read surface — no denied card, the
    // list/graph view toggle, and the primary control. NOTE (current
    // behavior): the "Add relative" button renders for the delegate too; only
    // the DATA layer is permission-gated, and a delegate write would 403
    // server-side (relations:manage is not delegated).
    await expect(
      page.getByRole('heading', { name: 'Access denied' })
    ).toHaveCount(0);
    await expect(page.getByRole('group', { name: 'View mode' })).toBeVisible();
    await expect(
      page.getByRole('button', { name: 'Add relative', exact: true })
    ).toBeVisible();
    // Data may legitimately be empty (fresh database, or another spec cleaned
    // up after itself) — pin the non-denied surface, not specific rows.
    await expect(
      page
        .getByRole('table')
        .or(page.getByRole('heading', { name: 'No people yet' }))
    ).toBeVisible();
  });

  test('admin can add a relative through the create modal', async ({
    roleSession,
    role,
    page,
  }) => {
    test.skip(
      role !== 'admin',
      'Admin-only mutation; the user/delegate surface is pinned above.'
    );

    await roleSession.shell.clickNav('Family Relations');
    await page.waitForURL('**/admin/relations');
    await expect(
      page.getByRole('heading', { name: 'Family Relations' })
    ).toBeVisible();

    createdPersonName = `Matrix Person ${uniqueSuffix()}`;

    // The header action is always present in the non-denied state ("Add
    // relative"; exact:true keeps it from also matching the empty state's
    // "Add the first relative" CTA).
    await page.getByRole('button', { name: 'Add relative', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Add a relative')).toBeVisible();
    await dialog.locator('#person-name').fill(createdPersonName);
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(toastWithText(page, 'Person created')).toBeVisible();

    // The refetched list (list view is the default) shows the new person.
    await expect(
      page.getByRole('cell', { name: createdPersonName })
    ).toBeVisible();
  });
});
