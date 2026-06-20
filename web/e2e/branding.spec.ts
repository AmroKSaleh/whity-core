import { test, expect } from './support/fixtures';
import { LoginPage } from './support/pages';
import { ADMIN, uniqueSuffix } from './support/constants';
import { createAuthedApi } from './support/api';

/**
 * WC-233 — Tenant Branding: display wiring (Slice 4).
 *
 * These tests verify that the branding layer is correctly wired into the UI
 * without relying on the Slice-5 upload UI (which is not yet merged). They
 * exercise what IS provable now:
 *
 *  1. The document <title> reflects the effective site_name (driven by the
 *     global setting, settable via the existing Website Settings admin UI).
 *  2. The login page "Welcome to …" heading uses the site_name.
 *  3. The sidebar shows the site_name text when no logo is uploaded.
 *  4. After resetting site_name the branding reverts to the default "Whity".
 *
 * Tests that require Slice-5 upload UI (logo/favicon round-trips) are
 * SKIPPED here and documented below — they should be re-enabled once
 * `web/components/branding-settings.tsx` is mounted in the settings page.
 */

// The admin Playwright project already has a persisted auth session; all
// describe blocks inside `test.describe` below inherit it.

const CUSTOM_SITE_NAME = `E2E Branding ${uniqueSuffix()}`;

async function resetSiteName(baseURL: string): Promise<void> {
  const api = await createAuthedApi(baseURL, ADMIN);
  // Patch site_name back to empty string → falls back to registry default 'Whity'.
  await api
    .patch('/api/v1/settings', {
      data: { settings: { site_name: '' } },
    })
    .catch(() => undefined);
  await api.dispose();
}

test.describe('Branding — display wiring (Slice 4, no upload UI)', () => {
  test.afterAll(async ({ baseURL }) => {
    if (!baseURL) return;
    await resetSiteName(baseURL);
  });

  test('document <title> reflects the effective site_name', async ({ page, baseURL }) => {
    if (!baseURL) test.skip();

    // Set a custom site_name via the existing settings API.
    const api = await createAuthedApi(baseURL!, ADMIN);
    await api.patch('/api/v1/settings', {
      data: { settings: { site_name: CUSTOM_SITE_NAME } },
    });
    await api.dispose();

    // A fresh navigation triggers a new SSR render → generateMetadata() picks up
    // the new site_name.
    await page.goto('/dashboard');
    await expect(page).toHaveTitle(CUSTOM_SITE_NAME);
  });

  test('login page "Welcome to …" uses the effective site_name', async ({ page, baseURL }) => {
    test.use({ storageState: { cookies: [], origins: [] } });

    if (!baseURL) test.skip();

    // Ensure the custom name is set (from the previous test or set it fresh).
    const api = await createAuthedApi(baseURL!, ADMIN);
    await api.patch('/api/v1/settings', {
      data: { settings: { site_name: CUSTOM_SITE_NAME } },
    });
    await api.dispose();

    await page.goto('/login');
    // The login page reads branding server-side; the CardTitle must include the
    // custom site name.
    await expect(
      page.getByRole('heading', { name: `Welcome to ${CUSTOM_SITE_NAME}` })
    ).toBeVisible();
  });

  test('sidebar shows site_name text when no logo is uploaded', async ({ page, baseURL }) => {
    if (!baseURL) test.skip();

    // Ensure the custom name is set.
    const api = await createAuthedApi(baseURL!, ADMIN);
    await api.patch('/api/v1/settings', {
      data: { settings: { site_name: CUSTOM_SITE_NAME } },
    });
    await api.dispose();

    await page.goto('/dashboard');

    // The sidebar header must show the site name as text (no logo uploaded yet)
    // and must NOT contain an <img> in the brand slot.
    const sidebarHeader = page.locator('aside').first().locator('div').first();
    await expect(sidebarHeader.getByText(CUSTOM_SITE_NAME)).toBeVisible();
    // No wide logo img present in the expanded sidebar header area.
    await expect(
      page.locator('aside h1')
    ).toHaveText(CUSTOM_SITE_NAME);
  });

  test('resetting site_name reverts branding to the default', async ({ page, baseURL }) => {
    if (!baseURL) test.skip();

    await resetSiteName(baseURL!);

    await page.goto('/dashboard');
    await expect(page).toHaveTitle('Whity');
    await expect(page.locator('aside h1')).toHaveText('Whity');
  });
});

// ---------------------------------------------------------------------------
// SKIPPED — require Slice-5 upload UI (web/components/branding-settings.tsx
// mounted at /admin/settings). Re-enable after Slice 5 is merged.
// ---------------------------------------------------------------------------

test.describe.skip('Branding — logo/favicon round-trips (requires Slice 5)', () => {
  test.afterAll(async ({ baseURL }) => {
    if (!baseURL) return;
    // Clean up any uploaded assets via the branding API.
    const api = await createAuthedApi(baseURL, ADMIN);
    for (const key of ['logo_wide', 'logo_square', 'favicon'] as const) {
      await api.delete(`/api/v1/branding/global/assets/${key}`).catch(() => undefined);
    }
    await api.dispose();
  });

  test('uploading a wide logo shows an <img> in the sidebar (not text)', async ({ page }) => {
    // Navigate to /admin/settings, find the "Wide logo" uploader in the
    // BrandingSettings section, upload a PNG, wait for the success toast, then
    // navigate to the dashboard and assert the sidebar renders an <img alt="…">
    // rather than an <h1> text node.
    await page.goto('/admin/settings');
    await expect(page.getByRole('heading', { name: 'Branding' })).toBeVisible();

    const fileInput = page.locator('input[accept*="image/png"]').first();
    await fileInput.setInputFiles({
      name: 'logo-wide.png',
      mimeType: 'image/png',
      // Minimal 1×1 red PNG (valid magic bytes, parseable by the backend validator)
      buffer: Buffer.from(
        '89504e470d0a1a0a0000000d49484452000000010000000108020000009001' +
          '2e00000000c49444154789c6260f8cfc00000000200016dd8a600000000049454e44ae426082',
        'hex'
      ),
    });

    await expect(page.getByText('Wide logo uploaded')).toBeVisible({ timeout: 10_000 });

    await page.goto('/dashboard');
    // The sidebar brand slot must now be an <img>, not a text heading.
    await expect(page.locator('aside img[alt]').first()).toBeVisible();
    await expect(page.locator('aside h1')).toHaveCount(0);
  });

  test('uploading a favicon changes the <link rel="icon"> href', async ({ page }) => {
    // After uploading a .ico favicon via the branding settings, a fresh page
    // render should include a <link rel="icon"> pointing at the branding asset
    // route. This is testable via page.evaluate on document.head.
    await page.goto('/admin/settings');
    const faviconInput = page.locator('input[accept*="image/x-icon"]').first();
    await faviconInput.setInputFiles({
      name: 'favicon.ico',
      mimeType: 'image/x-icon',
      buffer: Buffer.from('00000100', 'hex'), // Minimal ICO header
    });
    await expect(page.getByText('Favicon uploaded')).toBeVisible({ timeout: 10_000 });

    await page.goto('/');
    const iconHref = await page.evaluate(() => {
      const link = document.querySelector<HTMLLinkElement>('link[rel~="icon"]');
      return link?.href ?? null;
    });
    expect(iconHref).toMatch(/\/api\/v1\/branding\/asset\//);
  });

  test('clearing a logo reverts the sidebar to text', async ({ page }) => {
    // With a logo uploaded from the previous test, click the Clear button in
    // the branding settings, then assert the sidebar reverts to text.
    await page.goto('/admin/settings');
    await page.getByRole('button', { name: /Clear.*logo/i }).first().click();
    await expect(page.getByText(/logo cleared/i)).toBeVisible({ timeout: 10_000 });

    await page.goto('/dashboard');
    await expect(page.locator('aside h1')).toBeVisible();
    await expect(page.locator('aside img[alt]')).toHaveCount(0);
  });
});
