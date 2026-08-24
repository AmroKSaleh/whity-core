import { test, expect } from './support/fixtures';

/**
 * The document RECORD page — `/admin/document-library/[id]` (#993).
 *
 * WHAT THIS FILE PROVES, AND WHY IT IS MOSTLY ABOUT THE ADDRESS. #960's bar for
 * a record route is that a DEEP LINK works on a hard reload, not merely on a
 * client-side navigation — and a reload is the only thing that actually tests it,
 * because it throws away every scrap of state the click handed over and leaves
 * the URL. So the journeys here reload, and they go back with the BROWSER's back
 * button rather than the page's own control: a record page that only works via
 * its own affordance is still a modal with a URL bar.
 *
 * SHARED-DB DISCIPLINE, AND WHY NO DOCUMENT IS CREATED HERE. An issued document
 * can only come into existence through `POST /document-templates/{id}/render`
 * with `persist: true`, which needs BOTH the `documents.render_enabled` setting
 * (default off) and the `render` compose service (opt-in, `--profile render`).
 * Worse, there is no `DELETE /api/documents/{id}` — a document is an immutable
 * record on purpose — so a spec that issued one would litter the shared database
 * permanently, every run, forever. That is the one thing `web/README.md`'s
 * shared-DB discipline forbids outright.
 *
 * So this file asserts the two things it can assert without writing anything:
 * that the ROUTE is real and rebuilds itself from its address, and that the
 * library links into it. The record's rendering with real data is covered by
 * `web/__tests__/document-record-page.test.tsx`, which mounts the route file
 * from `params` alone against a served payload — and by
 * `tests/Api/DocumentRecordSectionsRealEngineTest.php` for the verdicts that
 * decide what appears on it.
 */

const LIBRARY = '/admin/document-library';

test.describe('the document record route', () => {
  test('a deep link to a document survives a hard reload and keeps its address', async ({
    adminPage,
    page,
  }) => {
    // An id no document has. The record page's refusal is the state under test:
    // core answers "removed" and "not shared with you" identically on purpose (a
    // 403 would confirm an enumerable id exists), so this is the same rendering
    // a real but invisible document produces.
    const recordUrl = `${LIBRARY}/999999999`;
    await adminPage.shell.clickNav('Documents');
    await page.waitForURL(`**${LIBRARY}`);

    await page.goto(recordUrl);

    // Not a blank frame and not a redirect. The reader is told which of the two
    // ambiguous things happened, in the words core actually gives (#756, #951).
    await expect(page.getByTestId('document-record-missing')).toBeVisible();
    await expect(page.getByText('This document is not available to you')).toBeVisible();
    expect(new URL(page.url()).pathname).toBe(recordUrl);

    // THE ACTUAL RELOAD, not a re-navigation. Nothing survives it except the
    // address, so this is the only way to prove the page is rebuilt from the
    // URL rather than from whatever reached it.
    await page.reload();

    await expect(page.getByTestId('document-record-missing')).toBeVisible();
    expect(new URL(page.url()).pathname).toBe(recordUrl);

    // The BROWSER's back button, distinct from the page's own back link.
    await page.goBack();
    await page.waitForURL(`**${LIBRARY}`);
    await expect(page.getByRole('heading', { name: 'Documents' }).first()).toBeVisible();
  });

  test('a non-numeric segment is refused without a request and without leaving', async ({
    adminPage,
    page,
  }) => {
    await adminPage.shell.clickNav('Documents');
    await page.waitForURL(`**${LIBRARY}`);

    const badUrl = `${LIBRARY}/not-a-document`;
    await page.goto(badUrl);

    await expect(page.getByTestId('document-record-bad-id')).toBeVisible();
    // The address stays. A route that bounced a malformed id back to the list
    // would hide a broken link somebody pasted, which is the thing they need to
    // see in order to fix it.
    expect(new URL(page.url()).pathname).toBe(badUrl);
  });

  test("the page's own back control returns to the library", async ({ adminPage, page }) => {
    await adminPage.shell.clickNav('Documents');
    await page.waitForURL(`**${LIBRARY}`);
    await page.goto(`${LIBRARY}/999999999`);
    await expect(page.getByTestId('document-record-missing')).toBeVisible();

    await page.getByRole('button', { name: 'Back to documents' }).click();

    // `push`, not history: a record reached from a pasted link has no entry to
    // go back TO, and `back()` there would leave the reader on another site.
    await page.waitForURL(`**${LIBRARY}`);
  });
});

test.describe('the library links into the record', () => {
  test('every document title is a link to that document record', async ({ adminPage, page }) => {
    await adminPage.shell.clickNav('Documents');
    await page.waitForURL(`**${LIBRARY}`);
    await expect(page.getByRole('heading', { name: 'Documents' }).first()).toBeVisible();

    const rows = page.locator('[data-testid^="document-row-"]');
    const count = await rows.count();

    // An EXPLICIT, reported skip rather than a silent branch. Issuing a document
    // needs the opt-in render tier (see the file docblock), so a stack without
    // it legitimately has nothing in the library — and a spec that quietly
    // passed on an empty table would be asserting nothing while looking green.
    test.skip(
      count === 0,
      'no issued documents in this stack: creating one needs documents.render_enabled and the opt-in render service, and a document cannot be deleted afterwards'
    );

    const first = rows.first();
    const href = await first.getAttribute('href');
    // A real anchor with a real href, not an onClick. Middle-click, ctrl-click
    // and "copy link address" are what make a row with an address different
    // from a row that opens a modal.
    expect(href).toMatch(new RegExp(`^${LIBRARY}/\\d+$`));

    await first.click();
    await page.waitForURL(new RegExp(`${LIBRARY}/\\d+$`));
    await expect(page.getByTestId('document-record')).toBeVisible();
    const recordUrl = page.url();

    // The full #960 journey on a REAL record: reload, then browser-back.
    await page.reload();
    await expect(page).toHaveURL(recordUrl);
    await expect(page.getByTestId('document-record')).toBeVisible();
    // The viewer states which version it is showing on every render, including
    // the single-version case — so its absence here would mean the record page
    // had quietly stopped mounting #986.
    await expect(page.locator('[data-slot="document-viewer-position"]').first()).toBeVisible();

    await page.goBack();
    await page.waitForURL(`**${LIBRARY}`);
    await expect(page.getByRole('heading', { name: 'Documents' }).first()).toBeVisible();
  });
});
