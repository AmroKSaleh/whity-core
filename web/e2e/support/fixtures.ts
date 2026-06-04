// The `use` calls below are Playwright's fixture-provider callback
// (`async ({ ... }, use) => { await use(value) }`), NOT the React `use` hook.
// eslint-config-next misfires its rules-of-hooks heuristic on the bare
// identifier `use`; this file has no React components or hooks.
/* eslint-disable react-hooks/rules-of-hooks */
import { test as base, expect, type APIRequestContext } from '@playwright/test';
import { ADMIN } from './constants';
import { AppShell } from './pages';
import { createAuthedApi } from './api';

/**
 * Custom fixtures shared across the suite.
 *
 * Authentication is handled at the PROJECT level (see playwright.config.ts):
 * the `setup` project logs in once per role and persists the browser storage
 * state; the `admin` and `user` projects load that state via `storageState`,
 * so the default `page`/`context` are already authenticated as the right role.
 * This avoids a slow UI login per test (the full login flow is still exercised
 * directly in auth.spec.ts, which runs in the unauthenticated `authflow`
 * project).
 *
 * `adminPage` / `userPage` simply land the already-authenticated page on the
 * dashboard and expose an `AppShell` page object. `adminApi` is an
 * authenticated APIRequestContext (admin) for setup/cleanup.
 */
interface RoleSession {
  shell: AppShell;
}

interface Fixtures {
  appShell: AppShell;
  adminPage: RoleSession;
  userPage: RoleSession;
  adminApi: APIRequestContext;
}

export const test = base.extend<Fixtures>({
  appShell: async ({ page }, use) => {
    await use(new AppShell(page));
  },

  adminPage: async ({ page }, use) => {
    await page.goto('/dashboard');
    await page.waitForURL('**/dashboard');
    await expect(page.getByRole('heading', { name: 'Welcome back!' })).toBeVisible();
    await use({ shell: new AppShell(page) });
  },

  userPage: async ({ page }, use) => {
    await page.goto('/dashboard');
    await page.waitForURL('**/dashboard');
    await expect(page.getByRole('heading', { name: 'Welcome back!' })).toBeVisible();
    await use({ shell: new AppShell(page) });
  },

  adminApi: async ({ baseURL }, use) => {
    if (!baseURL) {
      throw new Error('baseURL is required for the adminApi fixture');
    }
    const api = await createAuthedApi(baseURL, ADMIN);
    await use(api);
    await api.dispose();
  },
});

export { expect } from '@playwright/test';
