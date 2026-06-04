import { test, expect } from './support/fixtures';
import { LoginPage, AppShell } from './support/pages';
import { ADMIN } from './support/constants';

test.describe('Authentication flow', () => {
  test('valid login lands on the dashboard', async ({ page }) => {
    const login = new LoginPage(page);
    await login.goto();
    await login.submit(ADMIN);

    await page.waitForURL('**/dashboard');
    await expect(page.getByRole('heading', { name: 'Welcome back!' })).toBeVisible();
    await expect(page.getByText(`You're logged in as`)).toContainText(ADMIN.email);
  });

  test('invalid password keeps the user on /login and shows an error (WC-98)', async ({
    page,
  }) => {
    const login = new LoginPage(page);
    await login.goto();
    await login.submit({ email: ADMIN.email, password: 'definitely-wrong-password' });

    // The app must NOT navigate away from /login on bad credentials.
    await expect(page).toHaveURL(/\/login$/);
    // The dashboard heading must never appear.
    await expect(page.getByRole('heading', { name: 'Welcome back!' })).toHaveCount(0);

    // WC-98 (now fixed): the login page surfaces a destructive "Invalid
    // credentials" alert on bad credentials.
    await expect(page.getByText('Invalid credentials')).toBeVisible();
    await expect(login.emailInput).toBeVisible();
  });

  test('empty fields trigger inline client-side validation (no request fired)', async ({
    page,
  }) => {
    const login = new LoginPage(page);
    await login.goto();
    // Submitting with both fields blank short-circuits client-side: the form
    // shows "Email is required" / "Password is required" and never POSTs.
    await login.signInButton.click();
    await expect(page.getByText('Email is required')).toBeVisible();
    await expect(page.getByText('Password is required')).toBeVisible();
    await expect(page).toHaveURL(/\/login$/);
  });

  test('logout returns to /login', async ({ page }) => {
    const login = new LoginPage(page);
    await login.loginExpectingSuccess(ADMIN);

    await new AppShell(page).logout();
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('button', { name: 'Sign in' })).toBeVisible();
  });

  test('direct access to a protected route while logged out redirects to /login', async ({
    page,
  }) => {
    await page.goto('/admin/roles');
    await page.waitForURL('**/login');
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('button', { name: 'Sign in' })).toBeVisible();
  });

  test('session survives a full page reload', async ({ page }) => {
    const login = new LoginPage(page);
    await login.loginExpectingSuccess(ADMIN);
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.reload();

    // After reload, the app re-hydrates the session from the httpOnly cookie
    // (via /api/me) and should stay on the dashboard, not bounce to /login.
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByRole('heading', { name: 'Welcome back!' })).toBeVisible();
    await new AppShell(page).expectLoggedInAs(ADMIN.email);
  });
});
