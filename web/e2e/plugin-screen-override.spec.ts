import { test, expect } from './support/fixtures';

/**
 * #964 — a registered plugin screen override renders IN A REAL BROWSER.
 *
 * This is the half no unit test can reach. The plugin UI registry is a plain
 * module-level Map, so it has one instance in Next's server module graph and
 * another in the client one; the registrations lived in a module only the
 * SERVER component `app/layout.tsx` imported, while the lookup happens in the
 * `'use client'` host page. Jest has a single module graph and cannot tell the
 * two apart — only a browser can, because only a browser actually has the
 * client bundle in front of it.
 *
 * DemoCatalog is the feature that makes the failure legible. It declares
 * `screen: 'custom'` (see plugins/DemoCatalog/DemoCatalogPlugin.php), which
 * means the host has NO generic screen to derive for it: a registered override
 * is the only thing that can render it at all. Before #964 this route served
 * the "No screen registered" placeholder to every admin who opened it — the
 * page loaded, the header was right, and the screen was simply missing. Its
 * migration (GrantDemoCatalogPermissionsToAdmin) grants `demo_catalog:view` to
 * every `admin` role, so the feature reaches the admin's RBAC-filtered feature
 * list and nav in every environment the repo ships.
 *
 * The assertions below deliberately avoid the screen's copy. Both the
 * placeholder and the bespoke screen render the feature label as their <h1>, so
 * the heading cannot separate them; and the extracted DemoCatalog components
 * are currently mounted without a translator, so their visible strings are raw
 * i18n keys. What only the bespoke screen does is FETCH: it loads the plugin's
 * own collection through `demoCatalogAdapter` on mount. A request to
 * /api/v1/demo-catalog/items is therefore proof the override mounted, and it
 * stays proof after the copy is fixed.
 *
 * Read-only: it lists items and never creates any, so there is nothing to seed
 * or clean up.
 */

const FEATURE_ID = 'demo-catalog';
const FEATURE_LABEL = 'Demo Catalog';

test.describe('Plugin screen override: DemoCatalog (#964)', () => {
  test('the registered override renders, not the "no screen registered" placeholder', async ({
    adminPage,
    page,
  }) => {
    const link = adminPage.shell.navLink(FEATURE_LABEL);
    await expect(link).toBeVisible();

    // The bespoke screen's own data load. The placeholder issues no request at
    // all, so this is what fails — by timing out — if the registry is empty in
    // the browser again.
    const listResponse = page.waitForResponse(
      (res) =>
        res.url().includes('/api/v1/demo-catalog/items') &&
        res.request().method() === 'GET'
    );

    await link.click();
    await page.waitForURL(`**/admin/x/${FEATURE_ID}`);
    expect((await listResponse).status()).toBe(200);

    // The feature resolved (not the unknown-id fallbacks) …
    await expect(
      page.getByRole('heading', { name: FEATURE_LABEL, level: 1 })
    ).toBeVisible();
    await expect(
      page.getByRole('heading', { name: 'Feature unavailable' })
    ).toHaveCount(0);
    await expect(
      page.getByRole('heading', { name: 'Not available' })
    ).toHaveCount(0);

    // … and the host did NOT fall through to the placeholder it shows when a
    // `screen: 'custom'` feature has no registered component. This is the exact
    // string the route served for this feature before #964.
    await expect(
      page.getByRole('heading', { name: 'No screen registered' })
    ).toHaveCount(0);
  });

  test('a hard load of the feature URL renders the override too', async ({
    adminPage,
    page,
  }) => {
    void adminPage;

    // Nothing is carried over from a click: the page is mounted from the URL
    // alone, which is how the registrations' evaluation order is actually
    // exercised (the shell's client bundle must have run before the route
    // renders). A client-side navigation would let a late registration pass.
    const listResponse = page.waitForResponse(
      (res) =>
        res.url().includes('/api/v1/demo-catalog/items') &&
        res.request().method() === 'GET'
    );

    await page.goto(`/admin/x/${FEATURE_ID}`);
    expect((await listResponse).status()).toBe(200);

    await expect(
      page.getByRole('heading', { name: 'No screen registered' })
    ).toHaveCount(0);
  });
});
