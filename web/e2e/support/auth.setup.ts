import { test as setup, expect } from '@playwright/test';
import { ADMIN, REGULAR_USER } from './constants';
import { LoginPage } from './pages';
import { adminStatePath, userStatePath } from './storage';

/**
 * Auth setup project: log in once per role through the real UI and persist the
 * resulting browser storage (httpOnly auth cookies) to disk. The functional
 * specs then start already-authenticated by loading this state, which avoids
 * dozens of slow UI logins against the single dev server and removes the main
 * source of flakiness without skipping the auth flow itself (auth.spec.ts
 * still exercises the full login/logout/redirect/reload behaviour directly).
 */
setup('authenticate as admin', async ({ page }) => {
  const login = new LoginPage(page);
  await login.loginExpectingSuccess(ADMIN);
  await page.context().storageState({ path: adminStatePath });
  expect(page.url()).toContain('/dashboard');
});

setup('authenticate as regular user', async ({ page }) => {
  const login = new LoginPage(page);
  await login.loginExpectingSuccess(REGULAR_USER);
  await page.context().storageState({ path: userStatePath });
  expect(page.url()).toContain('/dashboard');
});
