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

test.describe('the document library', () => {
  test.afterEach(async ({ page }) => {
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
    const table = page.getByRole('table', { name: 'Documents' });
    await expect(table).toBeVisible();
    await expect(table).not.toBeEmpty();

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
    await expect(page.getByRole('table', { name: 'Documents' })).toBeVisible();

    await page.getByRole('button', { name: 'Grid', exact: true }).click();
    await expect(page.getByRole('table', { name: 'Documents' })).toHaveCount(0);

    await page.reload();

    // Still a grid after hydration — the half jsdom cannot exercise.
    await expect(page.getByRole('navigation', { name: 'Document folders' })).toBeVisible();
    await expect(page.getByRole('table', { name: 'Documents' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Grid', exact: true })).toHaveAttribute(
      'aria-pressed',
      'true'
    );

    // Back to a list, so the next spec in this project starts where it expects.
    await page.getByRole('button', { name: 'List', exact: true }).click();
    await expect(page.getByRole('table', { name: 'Documents' })).toBeVisible();
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
    await page.getByLabel('Name').fill(COLLECTION);
    await page.getByRole('button', { name: 'Create', exact: true }).click();

    // In the rail, from the server's own list rather than from optimism.
    const entry = rail.getByRole('button', { name: COLLECTION });
    await expect(entry).toBeVisible();

    await entry.click();
    await expect(page.getByRole('table', { name: 'Documents' })).toBeVisible();

    const renamed = `${COLLECTION} renamed`;
    await page.getByRole('button', { name: 'This collection' }).click();
    await page.getByRole('menuitem', { name: /Rename/ }).click();
    await page.getByLabel('Name').fill(renamed);
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
