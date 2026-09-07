import { test, expect } from './support/fixtures';

/**
 * The front door: raising a document from the organizer (#947 item 1).
 *
 * WHAT THIS FILE PROVES, AND WHERE IT STOPS
 * -----------------------------------------
 * It walks the real journey — Documents → New document → a picker filled from
 * the tenant's REAL templates → that template's REAL placeholder fields — and
 * then stops at the submit button.
 *
 * The stop is not laziness. There is no `DELETE /api/documents/{id}`: a document
 * is an immutable record on purpose, so a spec that created one would leave a
 * row in the shared database on every run, forever, which is the one thing
 * `web/README.md`'s shared-DB discipline forbids outright. `document-record.spec.ts`
 * refuses to create a document for exactly this reason and says so.
 *
 * So the create ITSELF is proven where it can be proven and undone:
 *   - `tests/Api/DocumentCreateApiRealEngineTest.php` drives the real handler
 *     against a real schema — the values persisted, the template scoping, the
 *     origin unit, and what happens with the render tier off.
 *   - `web/__tests__/create-document-dialog.test.tsx` pins the request body,
 *     including the blank-field case that would otherwise issue documents
 *     carrying the template's demonstration text.
 *
 * WHY THE STOPPING POINT IS STILL WORTH A BROWSER
 * -----------------------------------------------
 * Everything up to the submit is exactly what a unit test cannot see: that the
 * button is reachable through the real nav for a real signed-in role, that the
 * dialog's picker is populated by a live `GET /document-templates` rather than
 * an empty dropdown, and that choosing a template renders the fields THAT
 * template declares. A picker that silently renders nothing is the failure this
 * feature is most likely to ship with, and it looks fine in every unit test that
 * supplies its own templates.
 */

const LIBRARY = '/admin/document-library';

test.describe('raising a document', () => {
  test('the organizer offers New document, and the picker is filled from real templates', async ({
    adminPage,
    page,
  }) => {
    await adminPage.shell.clickNav('Documents');
    await page.waitForURL(`**${LIBRARY}`);

    // The affordance itself. `admin` holds `documents:render`, which is the
    // capability the create route requires (migration 060 grants it, and
    // migration 113 is where the codebase already argued that holding it is what
    // "may bring a document into existence" means).
    const newDocument = page.getByRole('button', { name: 'New document' });
    await expect(newDocument).toBeVisible();
    await newDocument.click();

    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('New document')).toBeVisible();

    // The picker is populated from the live template list. Asserting it is
    // ENABLED and opens to at least one option is the point: an empty dropdown
    // and a working one look identical until you click, and the empty state is
    // rendered as prose instead precisely so the two can never be confused.
    const picker = dialog.getByRole('combobox');
    await expect(picker).toBeVisible();
    await picker.click();
    const options = page.getByRole('option');
    await expect(options.first()).toBeVisible();
    expect(await options.count()).toBeGreaterThan(0);

    // Nothing was created: the dialog is abandoned, and the address behind it is
    // unchanged. Closing without writing is the whole contract of this spec.
    // Escape closes the SELECT (Radix stops it there rather than letting it
    // reach the dialog), so the listbox goes and the dialog stays.
    await page.keyboard.press('Escape');
    await expect(options.first()).toBeHidden();
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    expect(new URL(page.url()).pathname).toBe(LIBRARY);
  });

  test("choosing a template shows THAT template's fields", async ({ adminPage, page }) => {
    await adminPage.shell.clickNav('Documents');
    await page.waitForURL(`**${LIBRARY}`);
    await page.getByRole('button', { name: 'New document' }).click();

    const dialog = page.getByRole('dialog');
    await dialog.getByRole('combobox').click();

    // The seeded starter set is unplaced, so every caller sees it; the demo
    // faculty circular is the one that carries placeholders, and it is present
    // only when the document demo has been seeded. Prefer it, fall back to
    // whatever the tenant does have — this spec is about the FIELDS following
    // the template, not about any one template existing.
    const circular = page.getByRole('option', { name: 'Demo faculty circular' });
    const seeded = (await circular.count()) > 0;
    if (seeded) {
      await circular.click();
      // The two placeholders the demo template declares, by their own labels,
      // each rendered as an input the author can type into.
      await expect(dialog.getByLabel('Reference')).toBeVisible();
      await expect(dialog.getByLabel('Date')).toBeVisible();
      // The SAMPLE is a hint and never a value. A pre-filled sample is the
      // sample that gets issued, reading "DEMO-0001" where a real reference
      // number belongs.
      await expect(dialog.getByLabel('Reference')).toHaveValue('');
      await expect(dialog.getByLabel('Reference')).toHaveAttribute('placeholder', 'DEMO-0001');
      // User content is bidi-neutral: an Arabic reference must read
      // right-to-left in its own box whatever the interface direction is.
      await expect(dialog.getByLabel('Reference')).toHaveAttribute('dir', 'auto');
    } else {
      await page.getByRole('option').first().click();
      // Whatever was chosen, the dialog commits to one of the two honest
      // states — fields, or an explicit statement that there are none. What it
      // must never do is show a bare form with nothing in it.
      const fields = dialog.getByText('Fields');
      const none = dialog.getByText('This template has no fields to fill in.');
      await expect(fields.or(none)).toBeVisible();
    }

    // Still nothing written.
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
  });
});
