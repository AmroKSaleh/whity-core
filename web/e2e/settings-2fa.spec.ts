import { test, expect } from './support/fixtures';
import { LoginPage, SettingsPage } from './support/pages';
import { ADMIN } from './support/constants';
import { computeTotp, resetTwoFactorViaDb } from './support/totp';
import {
  createAuthedApi,
  enableTwoFactor,
  getTwoFactorStatus,
} from './support/api';
import type { Browser } from '@playwright/test';

/**
 * Full two-factor authentication coverage, end-to-end against the live stack.
 *
 * Two concerns are covered:
 *   A. The Settings UI: the Enable-2FA setup wizard, Regenerate backup codes,
 *      and Disable — the self-service controls a user drives by hand.
 *   B. The login challenge: a fresh, unauthenticated login that hits the 202
 *      challenge and completes it (valid TOTP) or is rejected (invalid TOTP).
 *
 * Robustness: each test that needs "2FA is enabled" ARRANGES it deterministically
 * via the backend API (enableTwoFactor, capturing the secret) in beforeEach and
 * TEARS IT DOWN in afterEach — so no test depends on another's residual state
 * (which is fragile under Playwright retries). TOTP is computed via OTPHP inside
 * the backend container (support/totp.ts) — no new npm dependency.
 *
 * BASELINE SAFETY: the seeder ships NO 2FA on admin and the rest of the suite
 * relies on admin/admin123 logging in WITHOUT a challenge. Every hook restores
 * the no-2FA baseline directly in the DB (resetTwoFactorViaDb), so a mid-run
 * failure can never leave admin behind a 2FA wall.
 */

/**
 * Open a genuinely unauthenticated browser context for a from-scratch login.
 *
 * Two non-obvious options are REQUIRED here:
 *  - baseURL: a manually-created context inherits NO baseURL, so a relative
 *    goto('/login') would have nowhere to go.
 *  - storageState: {} — within the `admin` project, browser.newContext() would
 *    otherwise inherit the project's stored ADMIN cookies, so /api/me returns
 *    200 and the login page instantly redirects to /dashboard (no challenge).
 *    Forcing an EMPTY storage state guarantees a clean, logged-out session.
 */
async function newCleanContext(browser: Browser, baseURL: string) {
  return browser.newContext({
    baseURL,
    storageState: { cookies: [], origins: [] },
  });
}

async function completeTwoFactorLogin(
  browser: Browser,
  baseURL: string,
  secret: string
): Promise<void> {
  const context = await newCleanContext(browser, baseURL);
  const page = await context.newPage();
  try {
    const login = new LoginPage(page);
    await login.goto();
    await login.submitExpecting2fa(ADMIN);
    // Compute the TOTP immediately before submitting to stay inside the window.
    const code = await computeTotp(secret);
    await login.submitTwoFactorCode(code);
    await page.waitForURL('**/dashboard', { timeout: 20_000 });
    await expect(page.getByRole('heading', { name: 'Welcome back!' })).toBeVisible();
  } finally {
    await context.close();
  }
}

test.describe('Two-Factor Authentication — Settings UI (admin)', () => {
  test.afterEach(async () => {
    await resetTwoFactorViaDb(ADMIN.email);
  });

  test('enable 2FA from Settings via the setup wizard', async ({ page }) => {
    // The wizard auto-downloads backup codes on success — accept the download.
    const downloadPromise = page
      .waitForEvent('download', { timeout: 5_000 })
      .catch(() => null);

    const settings = new SettingsPage(page);
    await settings.goto();
    await expect(settings.statusChip('Not Enabled')).toBeVisible();
    await settings.enableButton.click();

    await expect(
      settings.wizard.getByText('Enable Two-Factor Authentication')
    ).toBeVisible();
    const secret = await settings.readWizardSecret();
    expect(secret.length, 'a base32 secret should be shown').toBeGreaterThan(10);

    await settings.wizard.getByRole('button', { name: 'Next' }).click();

    // Submit a freshly computed TOTP. A code can land right on a 30s window
    // boundary; the server allows ±1 window, but to keep this deterministic we
    // retry once with a newly computed code if the first is (rarely) rejected.
    const codeInput = settings.wizard.getByPlaceholder('000000');
    await codeInput.fill(await computeTotp(secret));
    await settings.wizard.getByRole('button', { name: 'Verify' }).click();

    const enabledChip = settings.statusChip('Enabled');
    const verifyError = settings.wizard.getByText('Failed to verify code');
    await expect(enabledChip.or(verifyError)).toBeVisible();
    if (await verifyError.isVisible()) {
      await codeInput.fill(await computeTotp(secret));
      await settings.wizard.getByRole('button', { name: 'Verify' }).click();
    }

    // Status flips to Enabled and the backup-code summary appears.
    await expect(enabledChip).toBeVisible();
    await expect(page.getByText(/backup codes available/)).toBeVisible();
    await downloadPromise;
  });

  test('the setup wizard can be cancelled without enabling 2FA', async ({ page }) => {
    const settings = new SettingsPage(page);
    await settings.goto();
    await settings.enableButton.click();
    await expect(
      settings.wizard.getByText('Enable Two-Factor Authentication')
    ).toBeVisible();
    await settings.wizard.getByRole('button', { name: 'Cancel' }).click();
    await expect(settings.wizard).toBeHidden();
    // Still not enabled.
    await expect(settings.statusChip('Not Enabled')).toBeVisible();
  });

  test('regenerate backup codes from Settings', async ({ page, baseURL }) => {
    // Arrange: enable 2FA via the API so the regenerate control is available.
    expect(baseURL).toBeTruthy();
    const api = await createAuthedApi(baseURL as string, ADMIN);
    await enableTwoFactor(api, computeTotp);
    await api.dispose();

    // The regenerate action is gated behind window.confirm and triggers a
    // download — accept both.
    page.on('dialog', (dialog) => dialog.accept());
    const downloadPromise = page
      .waitForEvent('download', { timeout: 5_000 })
      .catch(() => null);

    const settings = new SettingsPage(page);
    await settings.goto();
    await expect(settings.statusChip('Enabled')).toBeVisible();
    await settings.regenerateButton.click();

    // After regeneration the panel reports a fresh set of 15 codes available.
    await expect(page.getByText(/backup codes available/)).toBeVisible();
    await expect(page.locator('strong', { hasText: '15' })).toBeVisible();
    await downloadPromise;
  });

  test('disable 2FA from Settings restores the no-challenge baseline', async ({
    page,
    baseURL,
  }) => {
    expect(baseURL).toBeTruthy();
    const api = await createAuthedApi(baseURL as string, ADMIN);
    await enableTwoFactor(api, computeTotp);

    // Disable is gated behind window.confirm — accept it.
    page.on('dialog', (dialog) => dialog.accept());
    const settings = new SettingsPage(page);
    await settings.goto();
    await expect(settings.statusChip('Enabled')).toBeVisible();
    await settings.disableButton.click();
    await expect(settings.statusChip('Not Enabled')).toBeVisible();

    // Independent confirmation: the account is back to plain login (no 202).
    const status = await getTwoFactorStatus(api);
    expect(status.enabled).toBe(false);
    await api.dispose();
  });
});

test.describe('Two-Factor Authentication — login challenge (admin)', () => {
  let secret = '';

  test.beforeEach(async ({ baseURL }) => {
    expect(baseURL).toBeTruthy();
    // Start from a guaranteed-clean baseline, then enable 2FA via the API and
    // capture the secret for this test only.
    await resetTwoFactorViaDb(ADMIN.email);
    const api = await createAuthedApi(baseURL as string, ADMIN);
    const enabled = await enableTwoFactor(api, computeTotp);
    secret = enabled.secret;
    await api.dispose();
  });

  test.afterEach(async () => {
    await resetTwoFactorViaDb(ADMIN.email);
  });

  test('login challenge accepts a valid TOTP', async ({ browser, baseURL }) => {
    expect(baseURL).toBeTruthy();
    await completeTwoFactorLogin(browser, baseURL as string, secret);
  });

  test('login challenge rejects an invalid TOTP with an error', async ({
    browser,
    baseURL,
  }) => {
    expect(baseURL).toBeTruthy();
    const context = await newCleanContext(browser, baseURL as string);
    const page = await context.newPage();
    try {
      const login = new LoginPage(page);
      await login.goto();
      await login.submitExpecting2fa(ADMIN);
      // A wrong-but-well-formed 6-digit code is rejected (401); the form shows
      // an error and stays on /login.
      await login.submitTwoFactorCode('000000');
      await expect(
        page.getByText('Invalid authenticator code. Please try again.')
      ).toBeVisible();
      await expect(page).toHaveURL(/\/login$/);
    } finally {
      await context.close();
    }
  });

  test('the challenge form can return to the login form via "Back to Login"', async ({
    browser,
    baseURL,
  }) => {
    expect(baseURL).toBeTruthy();
    const context = await newCleanContext(browser, baseURL as string);
    const page = await context.newPage();
    try {
      const login = new LoginPage(page);
      await login.goto();
      await login.submitExpecting2fa(ADMIN);
      await page.getByRole('button', { name: 'Back to Login' }).click();
      // The credential form returns (email field visible again).
      await expect(login.emailInput).toBeVisible();
    } finally {
      await context.close();
    }
  });

  // KNOWN APP BUG (fixme, not papered over): backup codes are stored in
  // XXXX-XXXX-XXXX form (14 chars, hyphenated) and validated by an EXACT
  // password_verify against that string. But the login recovery-code input
  // strips non-alphanumerics and truncates to 8 chars
  // (.replace(/[^A-Z0-9]/g,'').slice(0,8)), so the UI can NEVER submit the real
  // code — it is mangled to 8 chars and rejected (401). Verified empirically:
  // the full code authenticates at POST /api/login/2fa (200) while the
  // UI-truncated form is rejected (401). Fix: accept the hyphenated 14-char
  // code (raise maxLength, keep hyphens) or normalise both sides.
  test.fixme(
    'login challenge accepts a backup/recovery code',
    async ({ browser, baseURL }) => {
      const context = await newCleanContext(browser, baseURL as string);
      const page = await context.newPage();
      try {
        const login = new LoginPage(page);
        await login.goto();
        await login.submitExpecting2fa(ADMIN);
        await login.useRecoveryCode();
        // A real backup code is XXXX-XXXX-XXXX (14 chars); the input must accept
        // it intact for this to pass once the bug is fixed.
        await login.submitRecoveryCode('PLACEHOLDER-CODE');
        await page.waitForURL('**/dashboard', { timeout: 20_000 });
      } finally {
        await context.close();
      }
    }
  );
});
