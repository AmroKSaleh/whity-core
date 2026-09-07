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
 * CLEANS UP AFTER ITSELF. Groups are TENANT-WIDE, so one left behind is one
 * every later run of every other spec sees; the name carries a unique suffix so
 * a failed run's leftovers are attributable rather than anonymous.
 */
test.describe('User groups (admin)', () => {
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

    await page.getByRole('button', { name: 'Save' }).click();

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
