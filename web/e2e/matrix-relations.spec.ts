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
 * LIVE-VERIFIED access map (WC-175 #191: the "Family Relations" nav link is
 * RBAC-filtered on requiredPermission relations:read; GET /api/persons and
 * GET /api/relations enforce the same):
 *   admin    -> link shown, 200 (role grant, core migration)
 *   user     -> link HIDDEN (no relations:read; filtered out server-side, so
 *               the page is not reachable via nav)
 *   delegate -> link shown, 200 — THE flagship delegated-read pin: the active
 *               relations:read delegation surfaces the link AND turns the plain
 *               user's 403 into a working read surface.
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
    if (role === 'user') {
      // The "Family Relations" nav link is filtered out server-side for the
      // plain user (no relations:read), so the page is not reachable via nav.
      await expect(
        roleSession.shell.navLink('Family Relations')
      ).toHaveCount(0);
      return;
    }

    const personsResponse = page.waitForResponse(
      (res) =>
        res.url().includes('/api/v1/v1/persons') && res.request().method() === 'GET'
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

    expect((await personsResponse).status()).toBe(200);
    expect((await relationsResponse).status()).toBe(200);

    await expect(
      page.getByRole('heading', { name: 'Family Relations' })
    ).toBeVisible();

    // admin AND delegate get the full READ surface — no denied card, and the
    // list/graph view toggle. The WRITE surface is caller-aware (WC-177, #205):
    // the "Add relative" header control is gated on relations:manage, read from
    // GET /api/me/capabilities. admin holds relations:manage (role grant); the
    // delegate holds relations:read ONLY, so the button is hidden client-side
    // (the data layer would 403 a delegate write regardless — the frontend now
    // mirrors that authority instead of dangling a dead control).
    await expect(
      page.getByRole('heading', { name: 'Access denied' })
    ).toHaveCount(0);
    await expect(page.getByRole('group', { name: 'View mode' })).toBeVisible();
    if (role === 'admin') {
      await expect(
        page.getByRole('button', { name: 'Add relative', exact: true })
      ).toBeVisible();
    } else {
      // delegate: relations:read WITHOUT relations:manage — the manage-gated
      // "Add relative" control is hidden, while the read surface stays intact.
      await expect(
        page.getByRole('button', { name: 'Add relative', exact: true })
      ).toHaveCount(0);
    }
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

  test('per-row write affordances follow relations:manage', async ({
    roleSession,
    role,
    page,
    baseURL,
  }) => {
    // user can't reach the page (nav link filtered out server-side); the
    // surface pin above already covers that role.
    test.skip(role === 'user', 'No read access; covered by the surface pin.');
    expect(baseURL, 'baseURL is configured').toBeDefined();

    // Seed two non-account persons + a relation between them AS ADMIN, so both
    // admin and delegate sessions observe the SAME data and the assertion is
    // purely about the caller-aware write affordances (WC-177). Cleaned up in
    // finally regardless of role/outcome.
    const admin = await createAuthedApi(baseURL!, ADMIN);
    const suffix = uniqueSuffix();
    const aliceName = `WC177 Alice ${suffix}`;
    const bobName = `WC177 Bob ${suffix}`;
    let aliceId: number | null = null;
    let bobId: number | null = null;
    try {
      const aliceRes = await admin.post('/api/v1/v1/persons', {
        data: { displayName: aliceName },
      });
      aliceId = ((await aliceRes.json()) as { data: { id: number } }).data.id;
      const bobRes = await admin.post('/api/v1/v1/persons', {
        data: { displayName: bobName },
      });
      bobId = ((await bobRes.json()) as { data: { id: number } }).data.id;
      // Sibling (symmetric, typeId 4) so the drawer lists one relation row.
      await admin.post('/api/v1/v1/relations', {
        data: {
          from: { kind: 'person', id: aliceId },
          to: { kind: 'person', id: bobId },
          relationshipTypeId: 4,
        },
      });

      await roleSession.shell.clickNav('Family Relations');
      await page.waitForURL('**/admin/relations');
      await expect(
        page.getByRole('cell', { name: aliceName })
      ).toBeVisible();

      // Open the detail drawer for Alice (the "View" row action is a READ
      // control, present for both roles).
      await page
        .getByRole('row', { name: new RegExp(aliceName) })
        .getByRole('button', { name: 'View' })
        .click();
      const drawer = page.getByRole('dialog');
      await expect(drawer.getByRole('heading', { name: aliceName })).toBeVisible();
      // The relations LIST (read) is visible to both roles.
      await expect(drawer.getByText('Sibling')).toBeVisible();
      await expect(drawer.getByText(bobName)).toBeVisible();

      if (role === 'admin') {
        // relations:manage -> the structural action row and the per-relation
        // Remove control are present and usable.
        await expect(
          drawer.getByRole('button', { name: 'Add relation' })
        ).toBeVisible();
        await expect(
          drawer.getByRole('button', { name: `Remove relation to ${bobName}` })
        ).toBeVisible();
      } else {
        // delegate (relations:read only) -> every write affordance is hidden,
        // while the relation row above stays visible.
        await expect(
          drawer.getByRole('button', { name: 'Add relation' })
        ).toHaveCount(0);
        await expect(drawer.getByRole('button', { name: 'Edit' })).toHaveCount(0);
        await expect(drawer.getByRole('button', { name: 'Delete' })).toHaveCount(0);
        await expect(
          drawer.getByRole('button', { name: `Remove relation to ${bobName}` })
        ).toHaveCount(0);
      }

      // Close the drawer, switch to the GRAPH view, and check the per-node
      // action menu (all-writes) against the same capability.
      await page.keyboard.press('Escape');
      await expect(drawer).toHaveCount(0);
      await page.getByRole('button', { name: 'Graph' }).click();
      await expect(page.getByTestId('relations-graph')).toBeVisible();
      const nodeMenu = page.getByRole('button', { name: `Actions for ${aliceName}` });
      if (role === 'admin') {
        await expect(nodeMenu).toBeVisible();
      } else {
        await expect(nodeMenu).toHaveCount(0);
      }
    } finally {
      // Removing Alice cascades her relations; remove Bob too. Best-effort.
      if (aliceId !== null) await deletePerson(admin, aliceId);
      if (bobId !== null) await deletePerson(admin, bobId);
      await admin.dispose();
    }
  });
});
