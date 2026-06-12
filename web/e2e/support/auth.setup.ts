import { test as setup, expect } from '@playwright/test';
import {
  ADMIN,
  DELEGATED_PERMISSIONS,
  DELEGATE_USER,
  REGULAR_USER,
} from './constants';
import { LoginPage } from './pages';
import { adminStatePath, delegateStatePath, userStatePath } from './storage';
import { createAuthedApi, ensureDelegation, ensureUser } from './api';
import { resetTwoFactorViaDb } from './totp';

/**
 * Auth setup project: log in once per role through the real UI and persist the
 * resulting browser storage (httpOnly auth cookies) to disk. The functional
 * specs then start already-authenticated by loading this state, which avoids
 * dozens of slow UI logins against the single dev server and removes the main
 * source of flakiness without skipping the auth flow itself (auth.spec.ts
 * still exercises the full login/logout/redirect/reload behaviour directly).
 */
setup('authenticate as admin', async ({ page }) => {
  // Self-heal: the 2FA spec enrols admin temporarily and restores the baseline
  // afterward, but a prior INTERRUPTED run could leave admin behind a 2FA wall,
  // which would make this plain UI login fail. Proactively clear any residual
  // 2FA so setup is always re-runnable from a clean baseline.
  await resetTwoFactorViaDb(ADMIN.email);
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

setup('authenticate as delegate', async ({ page, baseURL }) => {
  if (!baseURL) {
    throw new Error('baseURL is required to provision the delegate account');
  }

  // Provision the third role through the admin API: a plain `user`-role
  // account that receives its extra access ONLY via a delegation (admin is the
  // grantor, so the subset invariant is satisfied for DELEGATED_PERMISSIONS).
  // Both helpers are idempotent, so re-runs against the persistent dev
  // database reuse the existing account and its live delegations.
  const api = await createAuthedApi(baseURL, ADMIN);
  try {
    const granteeId = await ensureUser(api, {
      email: DELEGATE_USER.email,
      password: DELEGATE_USER.password,
      role: 'user',
    });
    await ensureDelegation(api, {
      granteeType: 'user',
      granteeId,
      permissions: [...DELEGATED_PERMISSIONS],
    });
  } finally {
    await api.dispose();
  }

  const login = new LoginPage(page);
  await login.loginExpectingSuccess(DELEGATE_USER);
  await page.context().storageState({ path: delegateStatePath });
  expect(page.url()).toContain('/dashboard');
});
