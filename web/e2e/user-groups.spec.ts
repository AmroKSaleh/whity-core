import { test, expect } from './support/fixtures';
import { uniqueSuffix } from './support/constants';

/**
 * #1015 — defining a user group through the UI, against the live stack.
 *
 * #999 shipped the group engine with no screen at all, so this spec is the proof
 * that the loop is closed end to end: a group can be defined by a person rather
 * than by a hand-written API call, the server is asked who it currently reaches
 * BEFORE it is saved, and the definition survives a reload.
 *
 * The route composer's picker is covered by unit tests rather than here, because
 * routing needs a document and the e2e stack seeds none — the composer's own
 * suite asserts the request body it sends, and the engine's real-engine suite
 * asserts what that body does.
 *
 * CLEANS UP AFTER ITSELF, ON EVERY PATH — not just the happy one (#1264).
 * Groups are TENANT-WIDE, so one left behind is one every later run of every
 * other spec sees. The test deletes its group on its last line, and an
 * `afterEach` removes whatever is still there when that line was never reached;
 * the name carries a unique suffix so a leftover is attributable rather than
 * anonymous.
 */
/**
 * Every group this file created on the server, for the afterEach net below.
 *
 * WHY A NET UNDER A TEST THAT ALREADY DELETES ITS OWN GROUP
 * ---------------------------------------------------------
 * Playwright abandons a test body at the first failed `expect`, and the delete
 * is the LAST thing this test does. Between the Save and that line sit two
 * visibility assertions, a full `page.reload()`, and a `toPass` block with a
 * twenty-second budget that this file's own comment records as having been
 * flaky on its first run. Every one of those is a path on which a TENANT-WIDE
 * group survives the run and is then visible to every later spec and every
 * human in the tenant.
 *
 * So the id is registered the moment the server confirms the row, before the
 * first assertion that could strand it. The in-body delete STAYS: it asserts
 * real behaviour — the row-action menu, the confirmation dialog, the row
 * leaving the list — and this is a net under it, not a replacement. A repeat
 * DELETE of an already-gone id 404s and is swallowed.
 */
const createdGroupIds: string[] = [];

test.describe('User groups (admin)', () => {
  /**
   * Through `page.request`, matching `document-designer.spec.ts`: it shares the
   * page's cookie jar, so it is already the authenticated admin session, and it
   * works whatever state the failure left the page in — which a UI control does
   * not. `X-Requested-With` is required; the backend refuses
   * cookie-authenticated state-changing requests without it (WC-160, see
   * e2e/support/api.ts).
   *
   * NOT through the `adminApi` fixture, though #1264 suggested it and it is
   * nominally the setup/cleanup tool. `createAuthedApi` performs a real
   * `POST /api/v1/login` and asserts on the result, so requesting it here would
   * add an authentication that can fail during FIXTURE SETUP — before the
   * `.catch()` below exists to swallow anything — and that failure would
   * replace the real one in the report. A cleanup path should not be able to
   * out-shout the thing it is cleaning up after.
   *
   * Failures are swallowed for the same reason.
   */
  test.afterEach(async ({ page }) => {
    for (const id of createdGroupIds.splice(0)) {
      await page.request
        .delete(`/api/v1/user-groups/${id}`, {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
        .catch(() => undefined);
    }
  });

  test('defines a group, previews who it reaches, and deletes it', async ({
    adminPage,
    page,
  }) => {
    const name = `E2E instructors ${uniqueSuffix()}`;

    await adminPage.shell.clickNav('User Groups');
    await page.waitForURL('**/admin/user-groups');
    await expect(page.getByRole('heading', { name: 'User Groups' })).toBeVisible();

    // The screen states what a group IS, because the whole design turns on it.
    await expect(page.getByText(/a named RULE/i)).toBeVisible();

    await page.getByRole('button', { name: /define a group/i }).click();
    await page.getByLabel('Name').fill(name);
    await page
      .getByLabel('Description (optional)')
      .fill('Defined by the #1015 end-to-end spec.');

    // The kind list is /api/v1/group-rules — the subset that can answer without
    // a document — so "Everyone in a user group" must NOT be offered here.
    await page.getByLabel('Who is in it').click();
    await expect(page.getByRole('option', { name: /everyone in a user group/i })).toHaveCount(0);
    await page.getByRole('option', { name: 'Everyone holding a role', exact: true }).click();

    await page.getByLabel('Role').click();
    await page.getByRole('option', { name: 'admin', exact: true }).click();

    // The point of the preview contract: know what the rule means before saving.
    await page.getByRole('button', { name: /who is in this right now/i }).click();
    const preview = page.locator('[data-slot="user-group-preview"]');
    await expect(preview).toBeVisible();
    await expect(preview).toContainText(/right now/i);
    // And the caveat that stops the sample reading as a stored membership list.
    await expect(preview).toContainText(/a group is a rule, not a saved list of people/i);

    // The id comes from the create call's OWN response rather than from a
    // lookup by name afterwards: the answer to this spec's own request names
    // this spec's own row, with nothing to disambiguate and no window in which
    // another writer could be credited to us.
    const created = page.waitForResponse(
      (r) => r.url().includes('/api/v1/user-groups') && r.request().method() === 'POST',
      { timeout: 15_000 }
    );
    await page.getByRole('button', { name: 'Save' }).click();

    const response = await created;
    expect(response.status(), 'saving should create the group').toBe(201);
    const groupId = String(((await response.json()) as { data: { id: number | string } }).data.id);
    // Registered HERE, not later: the row exists on the server as of this line,
    // so from here on no failure may leak it.
    createdGroupIds.push(groupId);

    // It is really there — reloaded from the server, not from local state.
    await expect(page.getByText(name)).toBeVisible();
    await page.reload();
    await expect(page.getByText(name)).toBeVisible();

    // Tidy up: tenant-wide rows outlive the run that made them.
    //
    // The open is retried rather than clicked once: Radix hands focus back to
    // the trigger after the list's post-save refetch re-renders the row, and a
    // click landing during that hand-off is swallowed silently. Same pattern
    // `document-designer.spec.ts` uses on its menus, and the reason this step
    // was flaky on its first run.
    //
    // The item click is INSIDE the retry, which is safe because it is not the
    // destructive act: it only opens a confirmation. The block therefore ends
    // at "the dialog is up", and the one irreversible click happens once,
    // outside it.
    const rowActions = page.getByRole('button', {
      name: new RegExp(`Actions for ${escapeRegExp(name)}`, 'i'),
    });
    const deleteItem = page.getByRole('menuitem', { name: /delete/i });
    const confirmDialog = page.getByRole('dialog');
    await expect(async () => {
      // Only click the trigger when it is actually CLOSED — a click on an open
      // one toggles it shut, so a naive retry loop opens and closes forever.
      if ((await rowActions.getAttribute('data-state')) !== 'open') {
        await rowActions.click();
      }
      await deleteItem.click({ timeout: 2_000 });
      await expect(confirmDialog).toBeVisible({ timeout: 2_000 });
    }).toPass({ timeout: 20_000 });

    // Scoped to the confirmation dialog: the row-action menu also spells its
    // item "Delete", and an unscoped query would be ambiguous under strict mode.
    await confirmDialog.getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(page.getByText(name)).toHaveCount(0);
  });
});

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
