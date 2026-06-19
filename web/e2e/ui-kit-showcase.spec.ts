import { test, expect } from './support/fixtures';

/**
 * WC-228 — UiKitShowcase example plugin (full-stack E2E).
 *
 * This is the CAPSTONE check for the SP1 server-driven plugin-UI block pipeline:
 *
 *   SDK BlockContract whitelist (WC-225)
 *     -> host BlockValidator validation in the loader + the frontend-features
 *        endpoint (WC-226/228)
 *       -> web BlockRenderer at /admin/x/[featureId] (WC-227)
 *         -> the UiKitShowcase plugin's single `ui-kit-reference` feature.
 *
 * The UiKitShowcase plugin ships in the repo under `plugins/`, so the dev stack
 * discovers it; its migration seeds `uikit:view` and grants it to the admin
 * role, so the admin's `GET /api/v1/frontend/features` includes the blocks
 * feature. This spec drives an ADMIN to the UI-Kit Reference screen and asserts
 * that representative blocks of every kind render — proving the whole pipeline
 * end-to-end against the live backend.
 *
 * It runs admin-authenticated (the `plugins-uikit` Playwright project loads the
 * admin storage state). The page is purely declarative (no API routes, no DB),
 * so there is nothing to seed or clean up.
 */

const FEATURE_ID = 'ui-kit-reference';
const FEATURE_LABEL = 'UI-Kit Reference';

test.describe('UiKitShowcase: UI-Kit Reference block screen (WC-228)', () => {
  test('the admin reaches the reference via the plugins nav group', async ({
    adminPage,
    page,
  }) => {
    // The feature is gated on uikit:view, which the migration grants to admin,
    // so the RBAC-filtered navigation surfaces the "UI-Kit Reference" link.
    const link = adminPage.shell.navLink(FEATURE_LABEL);
    await expect(link).toBeVisible();

    await link.click();
    await page.waitForURL(`**/admin/x/${FEATURE_ID}`);

    // The blocks screen rendered (not the "Feature unavailable" / "not
    // available" fallbacks the dynamic host shows for an unresolved feature).
    // The AdminHeader renders the feature label as the page's <h1> (level 1),
    // distinct from the block tree's own headings.
    await expect(
      page.getByRole('heading', { name: FEATURE_LABEL, level: 1 })
    ).toBeVisible();
    await expect(
      page.getByRole('heading', { name: 'Feature unavailable' })
    ).toHaveCount(0);
    await expect(
      page.getByRole('heading', { name: 'Not available' })
    ).toHaveCount(0);
  });

  test('representative blocks of every kind render on the page', async ({
    adminPage,
    page,
  }) => {
    void adminPage;
    // Navigate directly to the feature route — the admin holds uikit:view, so
    // the feature resolves from the permission-filtered feature list.
    await page.goto(`/admin/x/${FEATURE_ID}`);

    // The block renderer container is present, confirming a `screen: 'blocks'`
    // feature was resolved and handed to BlockRenderer.
    await expect(page.locator('[data-slot="block-renderer"]')).toBeVisible();

    // heading: the page title (h1) declared by the first heading block.
    await expect(
      page.getByRole('heading', { name: 'SP1 UI Blocks' })
    ).toBeVisible();

    // alert: the intro callout. Its title text is rendered by AlertRenderer.
    await expect(page.getByText('Platform-neutral by design')).toBeVisible();

    // The catalogue lives under tabs. Open the "Content" and "Data" tabs to
    // bring their demos into view, then assert representative blocks.
    await page.getByRole('tab', { name: 'Content' }).click();

    // badge: the success-variant pill in the badge demo row.
    await expect(
      page.getByText('success', { exact: true }).first()
    ).toBeVisible();

    // code: every demo pairs a live block with a code block — at least one
    // <pre> code sample must be present (the PHP snippet next to each block).
    await expect(page.locator('pre code').first()).toBeVisible();

    await page.getByRole('tab', { name: 'Data' }).click();

    // stat: a metric tile from the stat demo grid.
    await expect(page.getByText('Active users')).toBeVisible();

    // table: the block-catalogue table — a header cell and a body cell.
    await expect(
      page.getByRole('cell', { name: 'Block', exact: true })
    ).toBeVisible();
    await expect(
      page.getByRole('cell', { name: 'section', exact: true })
    ).toBeVisible();
  });
});
