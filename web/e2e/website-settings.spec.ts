import { test, expect, type Page } from '@playwright/test';
import { ADMIN, uniqueSuffix } from './support/constants';
import { createAuthedApi, ensureUser, ensureDelegation, deleteUser } from './support/api';
import { LoginPage } from './support/pages';

/**
 * E2E for the admin Website Settings page (global defaults + per-tenant
 * overrides), driven against the full live stack.
 *
 * Scenarios:
 *  1. ADMIN edits the tenant site_name + timezone, saves, and the values
 *     persist across a reload (effective settings reflect the override).
 *  2. A settings:read-ONLY delegate sees the tenant form as read-only and the
 *     Global defaults section is absent (settings:manage is required for it).
 *
 * The read-only delegate is provisioned in-spec through the admin API (role
 * `user`, then ONLY `settings:read` delegated by the admin grantor, who holds
 * all three settings permissions on a seeded database) so the shared e2e
 * fixtures are left untouched. The throwaway user is removed in cleanup, and
 * the admin's tenant overrides are reset so the shared dev DB stays clean.
 */

const READONLY_USER = {
  email: `e2e-settings-ro-${uniqueSuffix()}@example.com`,
  password: 'Delegate123!pw',
};

async function openWebsiteSettings(page: Page): Promise<void> {
  await page.goto('/admin/settings');
  await expect(page.getByRole('heading', { name: 'General' })).toBeVisible();
}

test.describe('Website Settings — admin (settings:read/write/manage)', () => {
  // Inherits the admin storage state from the `admin` Playwright project
  // (playwright.config.ts), so the default page is already authenticated.

  test.afterAll(async ({ baseURL }) => {
    if (!baseURL) return;
    const api = await createAuthedApi(baseURL, ADMIN);
    // Reset the admin tenant's overrides so the shared DB returns to defaults.
    await api
      .patch('/api/v1/settings', {
        data: { settings: { site_name: '', timezone: '', locale: '', support_email: '' } },
      })
      .catch(() => undefined);
    await api.dispose();
  });

  test('edits a tenant setting, saves, and it persists across reload', async ({ page }) => {
    await openWebsiteSettings(page);

    const newName = `E2E Site ${uniqueSuffix()}`;
    const newTz = 'Europe/Berlin';

    const siteName = page.getByLabel('Site name', { exact: true });
    await expect(siteName).toBeEnabled();
    await siteName.fill(newName);

    await page.getByLabel('Timezone', { exact: true }).selectOption(newTz);

    const saveResponse = page.waitForResponse(
      (res) =>
        res.url().includes('/api/v1/settings') &&
        res.request().method() === 'PATCH'
    );
    await page.getByRole('button', { name: 'Save tenant settings' }).click();
    const res = await saveResponse;
    expect(res.status(), 'PATCH /api/v1/settings should succeed').toBe(200);
    await expect(page.getByText('Tenant settings saved.')).toBeVisible();

    // Reload — the override is reflected in the effective settings.
    await page.reload();
    await expect(page.getByRole('heading', { name: 'General' })).toBeVisible();
    await expect(page.getByLabel('Site name', { exact: true })).toHaveValue(newName);
    await expect(page.getByLabel('Timezone', { exact: true })).toHaveValue(newTz);
  });

  test('platform defaults are off the tenant page and Sign-up is denied to a non-system-tenant admin (WC-235)', async ({ page }) => {
    // System-wide defaults are gated to the system tenant (id 0). ADMIN is a
    // REGULAR tenant admin: even though it holds settings:manage, it must NOT
    // see the Platform defaults card on the General page…
    await openWebsiteSettings(page);
    await expect(page.getByRole('heading', { name: 'Platform defaults' })).toHaveCount(0);
    await expect(page.getByLabel('Global site name', { exact: true })).toHaveCount(0);

    // …and must be denied when navigating directly to the (also system-tenant-only) Sign-up page.
    await page.goto('/admin/settings/signup');
    await expect(page.getByRole('heading', { name: 'Access Denied' })).toBeVisible();
    await expect(page.getByTestId('settings-section-signup')).toHaveCount(0);
  });
});

test.describe('Website Settings — read-only delegate (settings:read only)', () => {
  // This group performs its own UI login as the throwaway user, so it must NOT
  // inherit any persisted storage state.
  test.use({ storageState: { cookies: [], origins: [] } });

  let granteeId: number | null = null;

  test.beforeAll(async ({ baseURL }) => {
    if (!baseURL) throw new Error('baseURL is required to provision the read-only delegate');
    const api = await createAuthedApi(baseURL, ADMIN);
    granteeId = await ensureUser(api, {
      email: READONLY_USER.email,
      password: READONLY_USER.password,
      role: 'user',
    });
    // Admin holds settings:read on a seeded DB, so this single permission is
    // grantable; the delegate gets read-only access to Website Settings.
    await ensureDelegation(api, {
      granteeType: 'user',
      granteeId,
      permissions: ['settings:read'],
    });
    await api.dispose();
  });

  test.afterAll(async ({ baseURL }) => {
    if (!baseURL || granteeId === null) return;
    const api = await createAuthedApi(baseURL, ADMIN);
    await deleteUser(api, granteeId);
    await api.dispose();
  });

  test('tenant form is read-only and the Platform defaults section is hidden', async ({ page }) => {
    await new LoginPage(page).loginExpectingSuccess(READONLY_USER);

    await openWebsiteSettings(page);

    // The form renders (settings:read) but every field is disabled, and no
    // tenant save control is offered.
    await expect(page.getByLabel('Site name', { exact: true })).toBeDisabled();
    await expect(page.getByLabel('Timezone', { exact: true })).toBeDisabled();
    await expect(
      page.getByRole('button', { name: 'Save tenant settings' })
    ).toHaveCount(0);

    // settings:manage is absent → no Platform defaults section.
    await expect(page.getByRole('heading', { name: 'Platform defaults' })).toHaveCount(0);
  });
});
