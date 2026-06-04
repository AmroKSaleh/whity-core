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
    await this.page.goto('/login');
    await expect(this.emailInput).toBeVisible();
  }

  /** Fill and submit the login form without asserting the outcome. */
  async submit(creds: Credentials): Promise<void> {
    await this.emailInput.fill(creds.email);
    await this.passwordInput.fill(creds.password);
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
      (res) => res.url().includes('/api/login') && res.request().method() === 'POST'
    );
    await this.submit(creds);
    const res = await loginResponse;
    expect(res.status(), 'login should succeed (200)').toBe(200);
    await this.page.waitForURL('**/dashboard', { timeout: 20_000 });
    await expect(
      this.page.getByRole('heading', { name: 'Welcome back!' })
    ).toBeVisible();
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

  async expectLoggedInAs(email: string): Promise<void> {
    await expect(this.sidebar.getByText('Logged in as')).toBeVisible();
    await expect(this.sidebar.getByText(email, { exact: true })).toBeVisible();
  }

  async clickNav(label: string): Promise<void> {
    await this.navLink(label).click();
  }

  async logout(): Promise<void> {
    await this.logoutButton.click();
    await this.page.waitForURL('**/login');
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
