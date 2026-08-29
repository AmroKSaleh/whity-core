import { test, expect, type Page } from '@playwright/test';

/**
 * The document library, in a real browser against the live stack.
 *
 * WHAT NEEDS A BROWSER AND WHAT DOES NOT
 * --------------------------------------
 * `web/__tests__/document-library-browser.test.tsx` covers the decisions —
 * which empty state a zero-row page produces, which controls are disabled and
 * why, what goes on the wire — against a mocked API, which is the right place
 * for them. Four things it CANNOT cover, and this file exists for exactly those:
 *
 *  1. The rail is populated by the SERVER's view registry. Jest asserts the page
 *     renders whatever it is handed; only a live request shows that the
 *     registry, the substrate probe against the real schema, and the presenter
 *     agree. This is also why nothing here asserts WHICH folders exist beyond
 *     the two that need no routing facts — the routing-derived folders are being
 *     switched on in parallel, and a spec that pinned their absence would fail
 *     the day they land, for the wrong reason.
 *  2. The layout choice is remembered across a RELOAD. That is localStorage plus
 *     a Next hydration pass, and jsdom does not exercise the second half.
 *  3. The sort contract holds against the real endpoint. The server refuses an
 *     unknown sort with a 400, so a client sending the wrong parameter name gets
 *     an error page rather than an unsorted list — a mock cannot tell the
 *     difference because a mock accepts whatever it is sent.
 *  4. A collection round-trips: created, seen in the rail, renamed, deleted. Six
 *     endpoints, one unique index, and a rail that re-reads its own counts.
 *
 * Runs under the [admin] project's authenticated session; the admin role holds
 * `documents:read`, which is all of this surface's gate.
 */

/** Unique per run: the unique index is (tenant, profile, name), and runs repeat. */
const COLLECTION = `E2E library ${Date.now()}`;

async function openLibrary(page: Page) {
  await page.goto('/admin/document-library');
  // The rail is the last thing to settle (it waits on the views request), so it
  // is the honest readiness signal.
  await expect(page.getByRole('navigation', { name: 'Document folders' })).toBeVisible();
}

/**
 * Wait for the document pane to reach a TERMINAL state, and report which one.
 *
 * WHY NOT `getByRole('table', { name: 'Documents' })`
 * --------------------------------------------------
 * That is what this file used, and it was wrong in a way that passed. The kit's
 * `DataTable` renders a `<Table aria-label={ariaLabel}>` for its LOADING
 * skeleton too — same role, same accessible name, five rows of grey bars inside
 * it. So `expect(table).toBeVisible()` and `expect(table).not.toBeEmpty()` are
 * both satisfied by the placeholder, and the assertion that was supposed to pin
 * #756 ("never render placeholder rows") was being satisfied by placeholder
 * rows. It passed locally against a seeded stack because there really were
 * documents, and passed against an EMPTY stack because it won the race with the
 * fetch — then failed in CI, on the one run where the request resolved first and
 * the empty state had replaced the skeleton. The failing run was the honest one.
 *
 * So this waits on markup that exists in NEITHER the skeleton nor the loading
 * state: a real row's link, or the empty state's own sentence. `.or()` auto-waits
 * for whichever arrives, which is the property that matters most here — a guard
 * that does not wait reports "no documents" for a library that simply had not
 * loaded yet, and a spec built on that skips itself with a reason that is false.
 * That exact mechanism was found in `document-record.spec.ts` (a `count()` taken
 * too early), and this helper exists so it is not reintroduced one file over.
 *
 * The CI e2e stack seeds with a plain `php public/index.php seed` — no
 * `--with-fixtures`, no `--with-document-demo` — so the library there has ZERO
 * documents, while a developer running this locally against a demo-seeded stack
 * has seven. Both are legitimate; a spec that only holds on one of them is not.
 */
async function paneSettled(page: Page): Promise<'rows' | 'empty'> {
  const anyRow = page.locator('[data-testid^="document-row-"]').first();
  const emptyTitle = page.getByText('No documents in this folder', { exact: true });
  await expect(anyRow.or(emptyTitle).first()).toBeVisible({ timeout: 20_000 });
  return (await page.locator('[data-testid^="document-row-"]').count()) > 0 ? 'rows' : 'empty';
}

test.describe('the document library', () => {
  test.afterEach(async ({ page }) => {
    // PUT THE ACCOUNT BACK IN ENGLISH, unconditionally.
    //
    // The Arabic mirroring test below switches the language and switches back
    // at the end — but only if it REACHES the end. Its middle is a
    // `boundingBox()` on a layout-heavy pane, and when that times out the
    // restore never runs. The language is a per-profile column, so it survives
    // the test, the retry and every test after it in the same worker.
    //
    // That is how one flake became forty-seven failures on shard 2/4: the
    // account stayed in Arabic, and every later locator that matches English
    // text — `filter({ hasText: /\bDocuments$/ })` on the sidebar, most of
    // document-record.spec.ts — waited 45 seconds for a link now labelled
    // المستندات. The retry of the Arabic test then failed on its OPENING
    // assertion, because the page was already rtl.
    //
    // Through the API rather than the switcher: this has to work when the page
    // is in whatever state the failure left it in, and a UI control cannot be
    // relied on then. Failures are swallowed — this is cleanup, and turning it
    // into a second error would hide the first.
    try {
      await page.request.patch('/api/v1/settings/language', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        data: { language_code: 'en' },
      });
    } catch {
      // Best effort: a worker whose session is already gone has nothing to reset.
    }

    // Collections are per-user, so a leftover is invisible to everybody else —
    // but it still accumulates in this account's rail across runs, and a rail
    // with forty "E2E library …" entries makes the next failure unreadable.
    const response = await page.request.get('/api/v1/document-collections', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!response.ok()) return;
    const body = (await response.json()) as { data?: { id: number; name: string }[] };
    for (const collection of body.data ?? []) {
      if (collection.name.startsWith('E2E library ')) {
        await page.request.delete(`/api/v1/document-collections/${collection.id}`, {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
      }
    }
  });

  test('the rail is built from the server, and the pane is never simply blank', async ({ page }) => {
    await openLibrary(page);
    const rail = page.getByRole('navigation', { name: 'Document folders' });

    // Two folders that read nothing but the documents table itself, so they are
    // computable in every installation.
    await expect(rail.getByRole('button', { name: 'All documents' })).toBeEnabled();
    await expect(rail.getByRole('button', { name: 'Starred' })).toBeEnabled();

    // #756: the pane says something true whether or not there are documents. A
    // library with none must SAY so — never render nothing, and never render
    // placeholder rows.
    //
    // Asserted against a TERMINAL state, because the loading skeleton is itself
    // a `<table aria-label="Documents">` full of placeholder rows — see
    // paneSettled(). Both branches below assert something substantive, so this
    // cannot degrade into "whatever the pane did was fine": an empty library
    // must carry BOTH halves of its explanation, and a populated one must show
    // a real row with a real address.
    const state = await paneSettled(page);
    if (state === 'rows') {
      const first = page.locator('[data-testid^="document-row-"]').first();
      await expect(first).toBeVisible();
      await expect(first).toHaveAttribute('href', /\/admin\/document-library\/\d+$/);
    } else {
      await expect(page.getByText('No documents in this folder', { exact: true })).toBeVisible();
      // The description, not just the title: "says something true" means the
      // reader is told WHY the folder is empty, which is the half that stops an
      // empty query reading as a broken screen.
      await expect(
        page.getByText(/This folder is a query over what documents record/)
      ).toBeVisible();
    }
    // Whichever state it is, the pane is not the placeholder any more.
    await expect(page.locator('table[aria-busy="true"]')).toHaveCount(0);

    // Every rail entry is either usable or explains itself. A disabled folder
    // with no visible reason is the #951 regression, and it is the one thing
    // about the rail that can be checked without knowing which folders exist.
    for (const entry of await rail.getByRole('button').all()) {
      if (await entry.isDisabled()) {
        await expect(entry).toHaveAttribute('title', /\S/);
      }
    }
  });

  test('the layout choice survives a reload', async ({ page }) => {
    await openLibrary(page);
    const state = await paneSettled(page);
    const listBtn = page.getByRole('button', { name: 'List', exact: true });
    const gridBtn = page.getByRole('button', { name: 'Grid', exact: true });

    // The toggle's own pressed state is the property this test is named for —
    // it is what localStorage round-trips — and it is the ONLY observable that
    // distinguishes the two layouts on an empty library, where both render the
    // same empty state. The previous version asserted `table` counts instead;
    // on the CI stack (zero documents) `toHaveCount(0)` was trivially true
    // whether or not the switch had done anything, which is an empty result
    // standing in for a positive one.
    await expect(listBtn).toHaveAttribute('aria-pressed', 'true');

    await gridBtn.click();
    await expect(gridBtn).toHaveAttribute('aria-pressed', 'true');
    await expect(listBtn).toHaveAttribute('aria-pressed', 'false');

    // Where there ARE documents, the pane must genuinely swap markup — a toggle
    // that flips its own highlight and changes nothing else is the failure the
    // aria assertions alone cannot see. Where there are none, both layouts
    // legitimately render the same empty state, and asserting it is still a
    // positive claim about the grid having rendered at all.
    if (state === 'rows') {
      await expect(page.getByRole('list', { name: 'Documents' })).toBeVisible();
      await expect(page.getByRole('table', { name: 'Documents' })).toHaveCount(0);
    } else {
      await expect(page.getByText('No documents in this folder', { exact: true })).toBeVisible();
    }

    await page.reload();

    // Still a grid after hydration — the half jsdom cannot exercise.
    await expect(page.getByRole('navigation', { name: 'Document folders' })).toBeVisible();
    await paneSettled(page);
    await expect(gridBtn).toHaveAttribute('aria-pressed', 'true');
    await expect(listBtn).toHaveAttribute('aria-pressed', 'false');

    // Back to a list, so the next spec in this project starts where it expects.
    await listBtn.click();
    await expect(listBtn).toHaveAttribute('aria-pressed', 'true');
    if (state === 'rows') {
      await expect(page.getByRole('table', { name: 'Documents' })).toBeVisible();
    }
  });

  test('the sort the toolbar offers is one the server accepts', async ({ page }) => {
    await openLibrary(page);
    await expect(page.getByRole('table', { name: 'Documents' })).toBeVisible();

    // The server refuses an unknown sort with a 400 rather than ignoring it, so
    // this asserts the parameter NAMES agree — which is the whole reason to run
    // it against the real endpoint instead of a mock that accepts anything.
    for (const option of ['Title', 'Date created', 'Template']) {
      const request = page.waitForResponse(
        (response) =>
          response.url().includes('/api/v1/documents?') && response.url().includes('sort=')
      );
      await page.getByRole('combobox', { name: 'Sort by' }).click();
      await page.getByRole('option', { name: option, exact: true }).click();

      expect((await request).status()).toBe(200);
    }

    // And reversing the applied direction is accepted too — the server defaults
    // it per field, so this is the branch that sends an explicit one.
    const reversed = page.waitForResponse((response) =>
      response.url().includes('direction=')
    );
    await page.getByRole('button', { name: /ending/ }).click();
    expect((await reversed).status()).toBe(200);
  });

  test('a collection is created, opened, renamed and deleted', async ({ page }) => {
    await openLibrary(page);
    const rail = page.getByRole('navigation', { name: 'Document folders' });

    await rail.getByRole('button', { name: 'New collection' }).click();
    // `getByRole('textbox')`, not `getByLabel('Name')`: the latter matches by
    // substring, so it also resolves the "Rename this collection" dialog itself
    // and fails strict mode. Found the hard way in the rename step below.
    await page.getByRole('textbox', { name: 'Name', exact: true }).fill(COLLECTION);
    await page.getByRole('button', { name: 'Create', exact: true }).click();

    // In the rail, from the server's own list rather than from optimism.
    const entry = rail.getByRole('button', { name: COLLECTION });
    await expect(entry).toBeVisible();

    await entry.click();
    // A collection that was created four lines ago is EMPTY — on every stack,
    // seeded or not. The old assertion here waited for a `<table>`, which could
    // only ever be satisfied by the PREVIOUS folder's table still being on
    // screen or by the loading skeleton; it was wrong even against a demo
    // stack, and passed by racing.
    //
    // What is actually true: this collection is now the open folder, and the
    // pane says the collection-specific thing rather than the generic one. That
    // sentence is one of the five `libraryEmptyState` resolves and this is the
    // only place it is exercised end-to-end.
    await expect(entry).toHaveAttribute('aria-current', 'page');
    await expect(page.getByText('Nothing is filed here yet', { exact: true })).toBeVisible();
    await expect(page.locator('table[aria-busy="true"]')).toHaveCount(0);

    const renamed = `${COLLECTION} renamed`;
    await page.getByRole('button', { name: 'This collection' }).click();
    await page.getByRole('menuitem', { name: /Rename/ }).click();
    await page.getByRole('textbox', { name: 'Name', exact: true }).fill(renamed);
    await page.getByRole('button', { name: 'Rename', exact: true }).click();

    await expect(rail.getByRole('button', { name: renamed })).toBeVisible();
    await expect(rail.getByRole('button', { name: COLLECTION, exact: true })).toHaveCount(0);

    await page.getByRole('button', { name: 'This collection' }).click();
    await page.getByRole('menuitem', { name: /Delete this collection/ }).click();
    await page.getByRole('button', { name: 'Delete', exact: true }).click();

    await expect(rail.getByRole('button', { name: renamed })).toHaveCount(0);
    // The open folder stopped existing, so the browser went back to a folder
    // that does — rather than leaving a collection_id that now answers 404.
    await expect(rail.getByRole('button', { name: 'All documents' })).toHaveAttribute(
      'aria-current',
      'page'
    );
  });

  test('the built-in starred collection shows its management controls, disabled, with the reason', async ({
    page,
  }) => {
    await openLibrary(page);

    await page.getByRole('navigation', { name: 'Document folders' })
      .getByRole('button', { name: 'Starred' })
      .click();
    await page.getByRole('button', { name: 'This collection' }).click();

    // Present and refused, not absent: the API answers 409 on both, and hiding
    // the controls would make "this one is built in" look identical to "you
    // lack a permission" and to "the feature was removed" (#951).
    const rename = page.getByRole('menuitem', { name: /Rename/ });
    await expect(rename).toHaveAttribute('data-disabled', /.*/);
    await expect(rename).toContainText(/built in/i);
    await expect(page.getByRole('menuitem', { name: /Delete this collection/ })).toHaveAttribute(
      'data-disabled',
      /.*/
    );
  });

  test('the whole browser mirrors under Arabic', async ({ page }) => {
    await openLibrary(page);
    const html = page.locator('html');
    const switcher = page.getByTestId('language-switcher').locator('select');

    await expect(html).toHaveAttribute('dir', 'ltr');
    const railBoxLtr = await page
      .getByRole('navigation', { name: 'Document folders' })
      .boundingBox();

    await switcher.selectOption('ar');
    await expect(html).toHaveAttribute('dir', 'rtl');

    // The rail is a layout-heavy pane and it must MOVE, not merely re-label: a
    // screen built with physical margins keeps its sidebar on the left under
    // rtl and looks almost right, which is how mirroring bugs survive review.
    const railBoxRtl = await page
      .getByRole('navigation', { name: 'Document folders' })
      .boundingBox();
    expect(railBoxRtl!.x).toBeGreaterThan(railBoxLtr!.x);

    // Leave the shared account in English, as rtl-direction.spec.ts does.
    await switcher.selectOption('en');
    await expect(html).toHaveAttribute('dir', 'ltr');
  });
});
