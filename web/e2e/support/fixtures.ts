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

/**
 * The three authenticated roles of the multi-role matrix (WC-173).
 *
 * MATRIX SPEC CONTRACT — how a `matrix-*.spec.ts` file works:
 *
 * A matrix spec is written ONCE and runs under all three `matrix-admin` /
 * `matrix-user` / `matrix-delegate` projects (their shared `testMatch` picks up
 * every `e2e/matrix-*.spec.ts` file). Each project loads a different storage
 * state, so the default `page` is already authenticated as that project's
 * role. The spec finds out which role it is running as via the `role` fixture
 * — derived from the project name's `matrix-` suffix — and branches its
 * EXPECTATIONS on it (same journey, role-dependent outcome):
 *
 *   test('relations page', async ({ roleSession, role, page }) => {
 *     await roleSession.shell.clickNav('Family Relations');
 *     if (role === 'user') {
 *       await expect(page.getByRole('heading', { name: 'Access denied' })).toBeVisible();
 *     } else {
 *       // admin AND delegate hold relations:read (delegate via delegation)
 *       await expect(page.getByRole('heading', { name: 'Access denied' })).toHaveCount(0);
 *     }
 *   });
 *
 * `roleSession` is the role-agnostic counterpart of `adminPage`/`userPage`:
 * it lands the already-authenticated page on the dashboard and exposes the
 * AppShell page object, whatever role the current project carries.
 */
export type MatrixRole = 'admin' | 'user' | 'delegate';

interface Fixtures {
  appShell: AppShell;
  adminPage: RoleSession;
  userPage: RoleSession;
  adminApi: APIRequestContext;
  role: MatrixRole;
  roleSession: RoleSession;
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

  // The current matrix role, derived from the project name (`matrix-admin` ->
  // 'admin', ...). Restricted to the `matrix-` projects ON PURPOSE: a matrix
  // spec picked up by any other project (say a rename lands it in the `admin`
  // testMatch) must fail loudly here, not silently run with a guessed role.
  role: async ({}, use, testInfo) => {
    const name = testInfo.project.name;
    const match = /^matrix-(admin|user|delegate)$/.exec(name);
    if (!match) {
      throw new Error(
        `The "role" fixture is only available in the matrix projects ` +
          `(matrix-admin|matrix-user|matrix-delegate), got "${name}". ` +
          'Matrix specs must be named e2e/matrix-*.spec.ts so only those ' +
          'projects pick them up.'
      );
    }
    await use(match[1] as MatrixRole);
  },

  roleSession: async ({ page, role }, use) => {
    // Depending on `role` (not just `page`) guarantees a loud, early failure
    // when a matrix spec is accidentally matched by a non-matrix project (the
    // `role` fixture throws for every project name without the matrix- prefix).
    void role;
    await page.goto('/dashboard');
    await page.waitForURL('**/dashboard');
    await expect(page.getByRole('heading', { name: 'Welcome back!' })).toBeVisible();
    await use({ shell: new AppShell(page) });
  },
});

export { expect } from '@playwright/test';
