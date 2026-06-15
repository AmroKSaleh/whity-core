import { test, expect } from './support/fixtures';
import { LoginPage } from './support/pages';
import { uniqueSuffix } from './support/constants';
import { deleteUser } from './support/api';
import { request, type APIRequestContext, type Browser, type Page } from '@playwright/test';

/**
 * Self-service profile management, end-to-end against the live stack (WC-64).
 *
 * Backed by PATCH /api/me, which edits ONLY the authenticated user. These tests
 * prove real PERSISTENCE — not just a green toast:
 *   - an email change is read back from GET /api/me (the source of truth the app
 *     uses to render the current user);
 *   - a password change is verified by logging in fresh with the NEW password and
 *     confirming the OLD one no longer works.
 *
 * SAFETY: every destructive change runs against a THROWAWAY user created per test
 * via the admin API and deleted in afterEach. The seeded admin account is never
 * mutated, so the rest of the suite (which depends on admin/admin123) is
 * unaffected even under Playwright retries.
 */

interface Throwaway {
  email: string;
  password: string;
  id: number;
}

/**
 * Create a throwaway regular user via the admin API and return its credentials.
 * The backend requires a password of at least 8 characters here.
 */
async function createThrowawayUser(api: APIRequestContext): Promise<Throwaway> {
  const email = `wc64-${uniqueSuffix()}@example.com`;
  const password = 'throwaway-pass-1';
  const res = await api.post('/api/v1/v1/users', {
    data: { email, password, role: 'user', tenantId: 1 },
  });
  expect(res.status(), 'creating the throwaway user should return 201').toBe(201);
  const body = (await res.json()) as { data: { id: number } };
  return { email, password, id: body.data.id };
}

/**
 * Log in through the UI as the given account in a clean context and land on the
 * dashboard, returning the page so the test can drive the Settings form.
 */
async function loginAs(
  browser: Browser,
  baseURL: string,
  email: string,
  password: string
): Promise<{ page: Page; close: () => Promise<void> }> {
  const context = await browser.newContext({
    baseURL,
    storageState: { cookies: [], origins: [] },
  });
  const page = await context.newPage();
  const login = new LoginPage(page);
  await login.loginExpectingSuccess({ email, password });
  return { page, close: () => context.close() };
}

test.describe('Self-service profile management (WC-64)', () => {
  let throwaway: Throwaway | null = null;

  test.afterEach(async ({ adminApi }) => {
    // Best-effort cleanup: the email may have changed during the test, so resolve
    // by id when we have it, else fall back to whatever email is current.
    if (throwaway) {
      await deleteUser(adminApi, throwaway.id);
      throwaway = null;
    }
  });

  test('the profile page is reachable from the sidebar user menu', async ({
    adminPage,
    page,
  }) => {
    // The "logged in as" footer is the account-settings entry point (it fixes the
    // previously orphaned /settings page). Clicking it lands on Settings.
    await adminPage.shell.sidebar.getByRole('link', { name: 'Account settings' }).click();
    await page.waitForURL('**/settings');
    await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();
    await expect(page.getByText('Profile', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Email Address')).toBeVisible();
  });

  test('changing the email persists and is reflected by GET /api/me', async ({
    browser,
    baseURL,
    adminApi,
  }) => {
    expect(baseURL).toBeTruthy();
    throwaway = await createThrowawayUser(adminApi);
    const newEmail = `wc64-renamed-${uniqueSuffix()}@example.com`;

    const session = await loginAs(browser, baseURL as string, throwaway.email, throwaway.password);
    try {
      const { page } = session;
      await page.goto('/settings');
      await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();

      // Edit the email and supply the current password to authorize the change.
      await page.getByLabel('Email Address').fill(newEmail);
      await page.getByLabel('Current Password').fill(throwaway.password);

      const patch = page.waitForResponse(
        (r) => r.url().includes('/api/v1/v1/me') && r.request().method() === 'PATCH'
      );
      await page.getByRole('button', { name: 'Save Changes' }).click();
      const patchRes = await patch;
      expect(patchRes.status(), 'PATCH /api/me should succeed').toBe(200);

      await expect(page.getByText('Profile updated successfully')).toBeVisible();

      // PERSISTENCE: re-fetch GET /api/me in the SAME authenticated session; the
      // backend re-issued the auth cookies, so the new email is returned.
      const me = await page.request.get('/api/v1/v1/me');
      expect(me.status()).toBe(200);
      const meBody = (await me.json()) as { user: { email: string } };
      expect(meBody.user.email).toBe(newEmail);

      // Keep cleanup correct: the user now has the new email (id is unchanged).
      throwaway.email = newEmail;
    } finally {
      await session.close();
    }
  });

  test('changing the password persists: the new password logs in, the old does not', async ({
    browser,
    baseURL,
    adminApi,
  }) => {
    expect(baseURL).toBeTruthy();
    throwaway = await createThrowawayUser(adminApi);
    const newPassword = 'rotated-pass-2';

    // 1) Change the password from the Settings form.
    const session = await loginAs(browser, baseURL as string, throwaway.email, throwaway.password);
    try {
      const { page } = session;
      await page.goto('/settings');
      await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();

      await page.getByLabel('New Password', { exact: true }).fill(newPassword);
      await page.getByLabel('Confirm New Password').fill(newPassword);
      await page.getByLabel('Current Password').fill(throwaway.password);

      const patch = page.waitForResponse(
        (r) => r.url().includes('/api/v1/v1/me') && r.request().method() === 'PATCH'
      );
      await page.getByRole('button', { name: 'Save Changes' }).click();
      expect((await patch).status()).toBe(200);
      await expect(page.getByText('Profile updated successfully')).toBeVisible();
    } finally {
      await session.close();
    }

    // 2) PERSISTENCE: a fresh login with the NEW password succeeds.
    const fresh = await loginAs(browser, baseURL as string, throwaway.email, newPassword);
    await fresh.close();

    // 3) The OLD password is now rejected (direct API login through the proxy).
    // The CSRF header (WC-160) is required so the request reaches the auth
    // layer at all — without it the guard answers 403 before credentials run.
    const probe = await request.newContext({
      baseURL: baseURL as string,
      extraHTTPHeaders: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    try {
      const loginRes = await probe.post('/api/v1/v1/login', {
        data: { email: throwaway.email, password: throwaway.password },
      });
      expect(loginRes.status(), 'the old password must be rejected').toBe(401);
    } finally {
      await probe.dispose();
    }
  });

  test('mismatched password confirmation is blocked client-side', async ({
    browser,
    baseURL,
    adminApi,
  }) => {
    expect(baseURL).toBeTruthy();
    throwaway = await createThrowawayUser(adminApi);

    const session = await loginAs(browser, baseURL as string, throwaway.email, throwaway.password);
    try {
      const { page } = session;
      await page.goto('/settings');
      await page.getByLabel('New Password', { exact: true }).fill('new-password-aa');
      await page.getByLabel('Confirm New Password').fill('new-password-bb');
      await page.getByLabel('Current Password').fill(throwaway.password);
      await page.getByRole('button', { name: 'Save Changes' }).click();

      await expect(page.getByText('Passwords do not match')).toBeVisible();
    } finally {
      await session.close();
    }
  });

  test('a wrong current password is rejected by the backend', async ({
    browser,
    baseURL,
    adminApi,
  }) => {
    expect(baseURL).toBeTruthy();
    throwaway = await createThrowawayUser(adminApi);

    const session = await loginAs(browser, baseURL as string, throwaway.email, throwaway.password);
    try {
      const { page } = session;
      await page.goto('/settings');
      await page.getByLabel('New Password', { exact: true }).fill('does-not-matter-1');
      await page.getByLabel('Confirm New Password').fill('does-not-matter-1');
      await page.getByLabel('Current Password').fill('totally-wrong');

      const patch = page.waitForResponse(
        (r) => r.url().includes('/api/v1/v1/me') && r.request().method() === 'PATCH'
      );
      await page.getByRole('button', { name: 'Save Changes' }).click();
      expect((await patch).status()).toBe(401);
      await expect(page.getByText('Current password is incorrect')).toBeVisible();
    } finally {
      await session.close();
    }
  });
});
