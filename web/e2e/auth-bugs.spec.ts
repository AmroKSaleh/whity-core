import { test, expect } from './support/fixtures';
import { LoginPage } from './support/pages';
import { ADMIN } from './support/constants';

/**
 * Regression guards for previously-broken auth behaviours.
 *
 * WC-98 (FIXED): app/login/page.tsx now surfaces a visible error on a failed
 * login. Previously handleSubmit swallowed the failure in a no-op catch and the
 * Alert (driven by useAuth().error) never fired, so invalid credentials showed
 * NO message. The fix sets a local loginError that renders a destructive Alert.
 * This test now asserts the working behaviour so a regression re-breaks the
 * build rather than silently passing.
 */
test.describe('Auth regression guards', () => {
  test('invalid login shows an error message to the user (WC-98)', async ({ page }) => {
    const login = new LoginPage(page);
    await login.goto();
    await login.submit({ email: ADMIN.email, password: 'definitely-wrong-password' });

    await expect(page).toHaveURL(/\/login$/);
    // WC-75 also fires a transient toast ("Login failed (401): Invalid
    // credentials"), so match the inline Alert's exact text to stay
    // unambiguous and avoid Next.js's empty route-announcer role="alert".
    await expect(
      page.getByText('Invalid credentials', { exact: true })
    ).toBeVisible();
  });

  test('the error clears when the user edits a field after a failed attempt', async ({
    page,
  }) => {
    const login = new LoginPage(page);
    await login.goto();
    await login.submit({ email: ADMIN.email, password: 'definitely-wrong-password' });
    // The persistent inline Alert text (exact match avoids the longer WC-75
    // toast text, which auto-dismisses).
    await expect(
      page.getByText('Invalid credentials', { exact: true })
    ).toBeVisible();

    // Typing into the email field clears the inline error (loginError reset onChange).
    // The transient toast also clears, so no "Invalid credentials" text remains.
    await login.emailInput.fill(`${ADMIN.email}x`);
    await expect(
      page.getByText('Invalid credentials', { exact: true })
    ).toHaveCount(0);
  });
});
