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
 * So this file asserts the three things it can assert without writing anything:
 * that the ROUTE is real and rebuilds itself from its address, that the library
 * links into it, and — on a stack that HAS an issued document — that the
 * viewer's artifact really loads rather than merely that the page mounted
 * (#1016, where a `content_url` missing its `/v1` 404'd on every document while
 * this spec stayed green). The record's rendering with real data is covered by
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
    // Settle BEFORE counting. `count()` does not auto-wait, and the heading this
    // test waits on is painted while the list request is still in flight — so on
    // a stack that HAS documents the count was taken at zero and the test below
    // skipped itself with a reason that was not true. Caught on a seeded stack
    // with five documents in the library, reporting "no issued documents".
    //
    // That is the skip guard failing in the direction that looks fine: a skip
    // reads as "correctly not applicable" in the report, so the one assertion
    // pinning that a title links to its record had stopped running without
    // anybody seeing a red. Racing the first row against the empty state gives a
    // real settle point and keeps the legitimate skip intact — a stack with no
    // documents still reaches the branch below, just deliberately.
    await Promise.race([
      rows.first().waitFor({ state: 'attached', timeout: 15_000 }),
      page.getByText(/No documents in this folder|not starred anything/).first()
        .waitFor({ state: 'visible', timeout: 15_000 }),
    ]).catch(() => {
      // Neither appeared: fall through to the count, which is then honestly zero
      // and skips with its stated reason rather than hanging the spec.
    });
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

    // ...AND THE FILE ITSELF ARRIVED (#1016).
    //
    // The position label above is rendered from the record payload, beside the
    // viewer, by a component that never looks at the fetch. It appeared happily
    // on top of a 404 for every document in the product, which is how a broken
    // `content_url` shipped past a green spec: the assertion was proof the page
    // mounted, and was being read as proof the document displayed.
    //
    // So assert the FETCH, not the furniture. The download affordance is the
    // discriminator that works in every environment: the viewer renders it in
    // all three of its loaded states — inline PDF, PDF this browser will not
    // frame, and not-a-PDF — and in NEITHER the loading nor the error state,
    // because it is an object URL over bytes that have actually arrived.
    //
    // Deliberately NOT the iframe: headless Chromium reports no inline-PDF
    // support, so the component correctly falls back to the download button and
    // an iframe assertion would fail on a working viewer.
    await expect(page.locator('[data-slot="document-viewer-download"]').first()).toBeVisible();
    // Named explicitly so a regression reads as "the file failed to load"
    // rather than as a timeout on a locator that was never going to appear.
    await expect(page.locator('[data-slot="document-viewer-content-error"]')).toHaveCount(0);

    // BOTH content_url builders, at the layer that emits them.
    //
    // The viewer only ever fetches an ARTIFACT's `content_url`; the document's
    // own — `DocumentPresenter::documentContentUrl()`, the durable link to the
    // current artifact — is on the wire but no UI reads it, so no amount of
    // clicking can catch it pointing at a 404. It broke identically and had to
    // be fixed identically, so it is asserted here where it is observable.
    const documentId = Number(new URL(page.url()).pathname.split('/').pop());
    expect(Number.isInteger(documentId)).toBe(true);

    const record = await page.request.get(`/api/v1/documents/${documentId}`);
    expect(record.ok()).toBe(true);
    // Core wraps a single resource in `data`.
    const doc = (await record.json()).data;
    expect(doc, `GET /api/v1/documents/${documentId} returned no data envelope`).toBeTruthy();

    // The document-level link is null only when nothing has been issued, and a
    // record reached from a library row always has an artifact.
    const emitted: string[] = [
      doc.content_url,
      ...(doc.artifacts ?? []).map((a: { content_url: string }) => a.content_url),
    ].filter((u): u is string => typeof u === 'string');
    // Both builders, or this loop is silently asserting nothing.
    expect(emitted.length).toBeGreaterThanOrEqual(2);

    for (const url of emitted) {
      // Route paths are written unversioned and gain '/v1' at REGISTRATION;
      // anything handed to a client has to carry it already. `apiClient` adds
      // nothing, so an unversioned emission is a guaranteed 404.
      expect(url, `${url} must be a versioned, client-callable path`).toMatch(
        /^\/api\/v1\/documents\//
      );

      const content = await page.request.get(url);
      expect(content.status(), `GET ${url}`).toBe(200);
      expect(content.headers()['content-type'], `GET ${url}`).toContain('application/pdf');
      // Bytes, not an error page that happens to carry a PDF content type.
      const body = await content.body();
      expect(body.subarray(0, 5).toString('latin1'), `GET ${url}`).toBe('%PDF-');
    }

    await page.goBack();
    await page.waitForURL(`**${LIBRARY}`);
    await expect(page.getByRole('heading', { name: 'Documents' }).first()).toBeVisible();
  });
});
