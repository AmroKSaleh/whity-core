import { test, expect } from './support/fixtures';
import { ADMIN, uniqueSuffix } from './support/constants';
import { createAuthedApi } from './support/api';

/**
 * WC-233 — Tenant Branding: display wiring (Slice 4, no upload UI).
 *
 * These tests verify that the branding layer is correctly wired into the UI
 * without relying on the Slice-5 upload UI (which is not yet merged). They
 * exercise what IS provable now:
 *
 *  1. The document <title> reflects the effective site_name (driven by the
 *     global setting, settable via the existing Website Settings admin UI).
 *  2. The login page "Welcome to …" heading uses the site_name.
 *  3. The sidebar shows the site_name text when no logo is uploaded.
 *  4. Resetting site_name to a known test value and restoring the original
 *     correctly reverts branding (self-contained, re-runnable).
 *
 * Tests that require Slice-5 upload UI (logo/favicon round-trips) are
 * documented below — they should be re-enabled once
 * `web/components/branding-settings.tsx` is mounted in the settings page.
 *
 * NOTE: All display assertions read the EFFECTIVE site_name at runtime from
 * GET /api/v1/branding so they are environment-independent (they pass whether
 * the DB default is "Whity", "KeyHub", or anything else).
 */

// The admin Playwright project already has a persisted auth session; all
// describe blocks inside `test.describe` below inherit it unless overridden.

// ---------------------------------------------------------------------------
// Branding response shape returned by GET /api/v1/branding.
// ---------------------------------------------------------------------------
interface BrandingData {
  siteName: string;
  logoWideUrl: string | null;
  logoSquareUrl: string | null;
  faviconUrl: string | null;
}

/**
 * Fetch the current effective branding from the backend.
 * The endpoint is public (no auth required) and proxied by Next.js at /api/v1/*.
 */
async function fetchEffectiveBranding(baseURL: string): Promise<BrandingData> {
  const res = await fetch(`${baseURL}/api/v1/branding`);
  if (!res.ok) {
    throw new Error(`GET /api/v1/branding returned ${res.status}`);
  }
  const body = (await res.json()) as { data: BrandingData };
  return body.data;
}

test.describe('Branding — display wiring (Slice 4, no upload UI)', () => {
  test('document <title> reflects the effective site_name', async ({ page, baseURL }) => {
    if (!baseURL) test.skip();

    const branding = await fetchEffectiveBranding(baseURL!);
    const { siteName } = branding;

    await page.goto('/dashboard');
    await expect(page).toHaveTitle(siteName);
  });

  test.describe('login page — unauthenticated', () => {
    // This group navigates to /login without any persisted auth session so
    // the page is not redirected away by the auth middleware.
    test.use({ storageState: { cookies: [], origins: [] } });

    test('login page "Welcome to …" uses the effective site_name', async ({ page, baseURL }) => {
      if (!baseURL) test.skip();

      // GET /api/v1/branding is public — no auth needed.
      const branding = await fetchEffectiveBranding(baseURL!);
      const { siteName } = branding;

      await page.goto('/login');
      // The login page reads branding server-side; the CardTitle must include
      // the effective site name.
      await expect(
        page.getByRole('heading', { name: `Welcome to ${siteName}` })
      ).toBeVisible();
    });
  });

  test('sidebar shows site_name text when no logo is uploaded', async ({ page, baseURL }) => {
    if (!baseURL) test.skip();

    const branding = await fetchEffectiveBranding(baseURL!);
    const { siteName, logoWideUrl, logoSquareUrl } = branding;

    await page.goto('/dashboard');

    // Only assert the text fallback when no logo is uploaded.
    // If a wide logo (or square logo as fallback) IS uploaded the sidebar
    // renders an <img> instead of <h1> text — mirroring the component logic.
    if (logoWideUrl !== null || logoSquareUrl !== null) {
      // Logo is present: the sidebar must show an <img>, not a text heading.
      await expect(page.locator('aside img[alt]').first()).toBeVisible();
      await expect(page.locator('aside h1')).toHaveCount(0);
    } else {
      // No logo: the sidebar header must show the site name as text.
      const sidebarHeader = page.locator('aside').first().locator('div').first();
      await expect(sidebarHeader.getByText(siteName)).toBeVisible();
      await expect(page.locator('aside h1')).toHaveText(siteName);
    }
  });

  test('resetting site_name reverts branding to the default', async ({ page, baseURL }) => {
    if (!baseURL) test.skip();

    // (a) Capture the current global site_name so we can restore it afterwards.
    const original = await fetchEffectiveBranding(baseURL!);
    const originalSiteName = original.siteName;

    const testSiteName = `E2E Brand ${uniqueSuffix()}`;
    const api = await createAuthedApi(baseURL!, ADMIN);

    try {
      // (b) Set a unique known test value via the GLOBAL settings endpoint.
      // admin@example.com is the system tenant (id 0) which has no per-tenant
      // override layer — it must use /api/v1/settings/global, NOT /api/v1/settings
      // (which 422s for the system tenant).
      const patchRes = await api.patch('/api/v1/settings/global', {
        data: { settings: { site_name: testSiteName } },
      });
      expect(
        patchRes.status(),
        `PATCH /api/v1/settings/global should return 200 (got ${patchRes.status()})`
      ).toBe(200);

      // (c) Reload and assert the title/sidebar reflect the test value.
      await page.goto('/dashboard');
      await expect(page).toHaveTitle(testSiteName);
      // Only assert sidebar text when no logo is present (same guard as above).
      const branding = await fetchEffectiveBranding(baseURL!);
      if (branding.logoWideUrl === null && branding.logoSquareUrl === null) {
        await expect(page.locator('aside h1')).toHaveText(testSiteName);
      }

      // (d) Restore the original site_name.
      const restoreRes = await api.patch('/api/v1/settings/global', {
        data: { settings: { site_name: originalSiteName } },
      });
      expect(
        restoreRes.status(),
        `restore PATCH /api/v1/settings/global should return 200`
      ).toBe(200);

      // (e) Assert the page reverted to the original.
      await page.goto('/dashboard');
      await expect(page).toHaveTitle(originalSiteName);
      if (branding.logoWideUrl === null && branding.logoSquareUrl === null) {
        await expect(page.locator('aside h1')).toHaveText(originalSiteName);
      }
    } finally {
      // Ensure restore runs even on failure so the suite stays re-runnable.
      await api
        .patch('/api/v1/settings/global', {
          data: { settings: { site_name: originalSiteName } },
        })
        .catch(() => undefined);
      await api.dispose();
    }
  });
});

// ---------------------------------------------------------------------------
// Logo/favicon round-trips — require Slice-5 upload UI
// (web/components/branding-settings.tsx mounted at /admin/settings).
//
// Controls are driven via data-testid attributes added by BrandingSettings:
//   branding-file-input-logo_wide-global   (hidden file input, wide logo global scope)
//   branding-file-input-favicon-global     (hidden file input, favicon global scope)
//   branding-clear-btn-logo_wide-global    (Clear button for wide logo global scope)
//   branding-upload-btn-logo_wide-global   (Upload trigger button)
// The global scope is used because admin@example.com is the system tenant
// (tenantOverridable=false), which shows only global controls.
// ---------------------------------------------------------------------------

test.describe('Branding — logo/favicon round-trips (requires Slice 5)', () => {
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
    // Navigate to /admin/settings, find the BrandingSettings "Wide logo"
    // global-scope file input (data-testid), upload a PNG, wait for the
    // success toast, then navigate to the dashboard and assert the sidebar
    // renders an <img alt="…"> rather than an <h1> text node.
    await page.goto('/admin/settings');
    await expect(page.getByRole('heading', { name: 'Branding' })).toBeVisible();

    // Use the data-testid selector for the hidden file input — more reliable
    // than the accept-attribute selector when multiple inputs share a type.
    const fileInput = page.getByTestId('branding-file-input-logo_wide-global');
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

    await expect(page.getByText('Wide logo uploaded successfully.')).toBeVisible({
      timeout: 10_000,
    });

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

    const faviconInput = page.getByTestId('branding-file-input-favicon-global');
    await faviconInput.setInputFiles({
      name: 'favicon.ico',
      mimeType: 'image/x-icon',
      buffer: Buffer.from('00000100', 'hex'), // Minimal ICO header
    });
    await expect(page.getByText('Favicon uploaded successfully.')).toBeVisible({
      timeout: 10_000,
    });

    await page.goto('/');
    const iconHref = await page.evaluate(() => {
      const link = document.querySelector<HTMLLinkElement>('link[rel~="icon"]');
      return link?.href ?? null;
    });
    expect(iconHref).toMatch(/\/api\/v1\/branding\/asset\//);
  });

  test('clearing a logo reverts the sidebar to text', async ({ page }) => {
    // With a logo uploaded from the previous test, click the Clear button for
    // the wide logo in the branding settings (global scope), then assert the
    // sidebar reverts to text.
    await page.goto('/admin/settings');
    await page.getByTestId('branding-clear-btn-logo_wide-global').click();
    await expect(page.getByText('Wide logo cleared.')).toBeVisible({ timeout: 10_000 });

    await page.goto('/dashboard');
    await expect(page.locator('aside h1')).toBeVisible();
    await expect(page.locator('aside img[alt]')).toHaveCount(0);
  });
});
