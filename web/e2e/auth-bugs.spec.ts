import { test, expect } from './support/fixtures';
import { LoginPage } from './support/pages';
import { ADMIN } from './support/constants';

/**
 * Documented, currently-failing behaviours captured as `test.fixme()` so they
 * are tracked in the report without breaking the green suite. Each one is a
 * REAL application bug, not a test issue. Remove the `.fixme` once the app is
 * fixed and the test should pass.
 */
test.describe('Known app bugs (auth)', () => {
  // BUG: app/login/page.tsx `handleSubmit` performs its own fetch and, on a
  // failed login, throws into a catch block that does nothing ("Error is
  // handled by displaying below"). The visible error <Alert> is driven by
  // `useAuth().error`, which is only ever set by the auth-context `login()`
  // method — but handleSubmit never calls it. Result: invalid credentials
  // produce NO visible error message (verified empirically on the live app).
  test('invalid login should show an error message to the user', async ({ page }) => {
    const login = new LoginPage(page);
    await login.goto();
    await login.submit({ email: ADMIN.email, password: 'definitely-wrong-password' });

    await expect(page).toHaveURL(/\/login$/);
    // Expected (once fixed): a destructive alert describing the failure.
    await expect(page.getByText(/invalid credentials|login failed/i)).toBeVisible();
  });
});
