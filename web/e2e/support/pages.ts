import { type Locator, type Page, expect } from '@playwright/test';
import { type Credentials } from './constants';

/**
 * Page object for the login screen and the auth lifecycle.
 */
export class LoginPage {
  readonly page: Page;
  readonly emailInput: Locator;
  readonly passwordInput: Locator;
  readonly signInButton: Locator;

  constructor(page: Page) {
    this.page = page;
    this.emailInput = page.locator('#email');
    this.passwordInput = page.locator('#password');
    this.signInButton = page.getByRole('button', { name: 'Sign in' });
  }

  async goto(): Promise<void> {
    // The login form is disabled while the auth context resolves its initial
    // /api/me check (`isLoading`), and the inputs flap between disabled/enabled
    // across those renders. A disabled click is a silent no-op (no /api/login
    // ever fires), so we must interact only AFTER that check settles. Race the
    // navigation against the /api/me response to get a deterministic settle
    // point, then assert the form is interactable.
    const mePromise = this.page
      .waitForResponse((res) => res.url().includes('/api/v1/me'), { timeout: 15_000 })
      .catch(() => null);
    await this.page.goto('/login');
    await expect(this.emailInput).toBeVisible();
    await mePromise;
    await expect(this.signInButton).toBeEnabled();
    await expect(this.emailInput).toBeEditable();
  }

  /** Fill and submit the login form without asserting the outcome. */
  async submit(creds: Credentials): Promise<void> {
    // Re-assert enabled immediately before each interaction: the auth context
    // can briefly toggle isLoading, disabling the inputs mid-flow.
    await expect(this.emailInput).toBeEditable();
    await this.emailInput.fill(creds.email);
    await this.passwordInput.fill(creds.password);
    await expect(this.signInButton).toBeEnabled();
    await this.signInButton.click();
  }

  /**
   * Log in and assert we land on the dashboard.
   *
   * The login page submits, awaits the auth state refresh, then client-side
   * navigates to /dashboard. We wait on the /api/login response first so the
   * subsequent navigation wait is not racing the network round-trip, then give
   * the SPA navigation a generous window (the redirect is driven by React
   * state, not a server redirect).
   */
  async loginExpectingSuccess(creds: Credentials): Promise<void> {
    await this.goto();
    const loginResponse = this.page.waitForResponse(
      (res) => res.url().includes('/api/v1/login') && res.request().method() === 'POST'
    );
    await this.submit(creds);
    const res = await loginResponse;
    expect(res.status(), 'login should succeed (200)').toBe(200);
    await this.page.waitForURL('**/dashboard', { timeout: 45_000 });
    await expect(
      this.page.getByRole('heading', { name: 'Welcome back!' })
    ).toBeVisible();
  }

  // --- 2FA login challenge ---------------------------------------------------

  /** The 2FA challenge heading text that appears once /api/login returns 202. */
  get twoFactorHeading(): Locator {
    return this.page.getByText('Enter your authenticator code');
  }

  /** The authenticator-code input shown after a 202 challenge. */
  get twoFactorCodeInput(): Locator {
    return this.page.locator('#twoFactorCode');
  }

  /** The recovery-code input shown after switching to backup-code mode. */
  get recoveryCodeInput(): Locator {
    return this.page.locator('#recoveryCode');
  }

  /**
   * Submit credentials and assert the app enters the 2FA challenge (202),
   * without completing it. Use when the account under test has 2FA enabled.
   */
  async submitExpecting2fa(creds: Credentials): Promise<void> {
    const loginResponse = this.page.waitForResponse(
      (res) => res.url().includes('/api/v1/login') && res.request().method() === 'POST'
    );
    await this.submit(creds);
    const res = await loginResponse;
    expect(res.status(), 'login with 2FA should return 202 (challenge)').toBe(202);
    await expect(this.twoFactorHeading).toBeVisible();
  }

  /** Enter a 6-digit authenticator code and click Verify. */
  async submitTwoFactorCode(code: string): Promise<void> {
    await this.twoFactorCodeInput.fill(code);
    await this.page.getByRole('button', { name: 'Verify', exact: true }).click();
  }

  /** Switch the challenge form to recovery-code mode. */
  async useRecoveryCode(): Promise<void> {
    await this.page
      .getByRole('button', { name: /Use a recovery code instead/ })
      .click();
  }

  /** Enter a full XXXX-XXXX-XXXX recovery code and click Verify Recovery Code. */
  async submitRecoveryCode(code: string): Promise<void> {
    await this.recoveryCodeInput.fill(code);
    await this.page
      .getByRole('button', { name: 'Verify Recovery Code' })
      .click();
  }
}

/**
 * Page object for the authenticated app shell (sidebar + common chrome).
 */
export class AppShell {
  readonly page: Page;
  readonly sidebar: Locator;
  readonly logoutButton: Locator;
  readonly loggedInEmail: Locator;

  constructor(page: Page) {
    this.page = page;
    this.sidebar = page.getByRole('complementary');
    this.logoutButton = page.getByRole('button', { name: 'Logout' });
    // The footer renders "Logged in as" above the email paragraph.
    this.loggedInEmail = this.sidebar.locator('p', { hasText: '@' }).last();
  }

  navLink(label: string): Locator {
    return this.sidebar.getByRole('link', { name: new RegExp(`\\b${escapeRegExp(label)}$`) });
  }

  /** The desktop collapse/expand toggle in the sidebar header (title attr). */
  get collapseToggle(): Locator {
    return this.sidebar.getByRole('button', {
      name: /Collapse sidebar|Expand sidebar/,
    });
  }

  /** The "Whity" brand heading, hidden when the sidebar is collapsed. */
  get brandHeading(): Locator {
    return this.sidebar.getByRole('heading', { name: 'Whity' });
  }

  async expectLoggedInAs(email: string): Promise<void> {
    await expect(this.sidebar.getByText('Logged in as')).toBeVisible();
    await expect(this.sidebar.getByText(email, { exact: true })).toBeVisible();
  }

  async clickNav(label: string): Promise<void> {
    await this.navLink(label).click();
  }

  async logout(): Promise<void> {
    // The sidebar's logout handler fires POST /api/auth/logout and pushes to
    // /login WITHOUT awaiting the request, so the URL changes while the
    // httpOnly cookie may still be valid. Wait for the logout RESPONSE (which
    // carries the cookie-clearing headers), not just the URL — otherwise an
    // immediate follow-up (e.g. re-login in a role-switch flow) races the
    // in-flight logout and /login bounces straight back to /dashboard.
    const logoutResponse = this.page.waitForResponse(
      (res) =>
        res.url().includes('/api/v1/auth/logout') &&
        res.request().method() === 'POST'
    );
    await this.logoutButton.click();
    await this.page.waitForURL('**/login');
    await logoutResponse;
  }
}

/**
 * Page object for the Settings page Security section, which hosts the
 * TwoFactorSettings component (status panel + enable wizard + regenerate /
 * disable controls). The wizard and the destructive confirms are driven via
 * window.confirm (a native dialog), so callers must pre-accept it on the page.
 */
export class SettingsPage {
  readonly page: Page;

  constructor(page: Page) {
    this.page = page;
  }

  async goto(): Promise<void> {
    await this.page.goto('/settings');
    await expect(this.page.getByRole('heading', { name: 'Settings' })).toBeVisible();
  }

  /** The 2FA status chip — "Enabled" (green) or "Not Enabled". */
  statusChip(state: 'Enabled' | 'Not Enabled'): Locator {
    return this.page.getByText(state, { exact: true });
  }

  get enableButton(): Locator {
    return this.page.getByRole('button', { name: 'Enable 2FA' });
  }

  get disableButton(): Locator {
    return this.page.getByRole('button', { name: /Disable 2FA/ });
  }

  get regenerateButton(): Locator {
    return this.page.getByRole('button', { name: /Regenerate Backup Codes/ });
  }

  /** The 2FA setup wizard dialog (Enable Two-Factor Authentication). */
  get wizard(): Locator {
    return this.page.getByRole('dialog');
  }

  /**
   * The base32 secret rendered in the wizard's "enter this code manually"
   * <code> element. Returned trimmed so it can be fed to computeTotp().
   */
  async readWizardSecret(): Promise<string> {
    const code = this.wizard.locator('code').first();
    await expect(code).toBeVisible();
    // The secret arrives with the async POST /api/auth/2fa/setup response, so
    // the styled (padded -> non-empty bounding box -> "visible") <code> block
    // can render before its text exists. Wait for actual base32 content with a
    // retrying assertion instead of racing a one-shot innerText read.
    await expect(code).toHaveText(/[A-Z2-7]{10,}=*/);
    // The dev server runs React StrictMode, which double-mounts the wizard's
    // setup effect: TWO POST /api/auth/2fa/setup calls race and the LAST
    // response wins the secret the wizard later submits to confirm. Reading
    // the first-rendered value can capture the LOSER — a TOTP computed from
    // it can never verify (live-reproduced: the displayed secret swapped
    // shortly after first paint). Only trust the secret once it has been
    // stable across a settle window.
    let secret = (await code.innerText()).trim();
    await expect(async () => {
      await this.page.waitForTimeout(750);
      const again = (await code.innerText()).trim();
      const stable = again === secret;
      secret = again;
      expect(stable, 'wizard secret should stop changing').toBe(true);
    }).toPass({ timeout: 15_000 });
    return secret;
  }
}

/**
 * A toast is a transient banner rendered bottom-right; success toasts are
 * green, error toasts are red. They auto-dismiss after ~3s, so assert with
 * web-first expectations immediately after the triggering action.
 */
export function toastWithText(page: Page, text: string | RegExp): Locator {
  return page.getByText(text);
}

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
