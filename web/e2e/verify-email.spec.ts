import { test, expect } from './support/fixtures';

/**
 * Email verification landing page (WC-235). The `page` fixture is
 * unauthenticated (a user verifying an email is typically not signed in), so
 * these drive the logged-out flow end to end through the real Next.js proxy →
 * backend path.
 *
 * The happy path (a genuine single-use token → verified) is covered by the
 * backend RealEngine suite; a real token cannot be minted from the browser
 * (it is emailed and stored only hashed), so here we exercise the states that
 * ARE reachable without one: the no-token resend form, an invalid/expired
 * token, and the generic (no-enumeration) resend confirmation.
 */
test.describe('Email verification (WC-235)', () => {
  test('with no token, shows the resend form', async ({ page }) => {
    await page.goto('/verify-email');

    await expect(page.getByTestId('verify-resend')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Send verification link' })).toBeVisible();
  });

  test('an invalid token shows a generic invalid/expired message with a resend option', async ({
    page,
  }) => {
    await page.goto('/verify-email?token=deadbeef-not-a-real-token');

    // The page auto-confirms on load; the backend returns a generic 400, so the
    // resend state is shown with the invalid/expired notice.
    await expect(page.getByTestId('verify-resend')).toBeVisible();
    await expect(page.getByText(/invalid or has expired/i)).toBeVisible();
  });

  test('the resend form reports a generic confirmation (no enumeration)', async ({ page }) => {
    await page.goto('/verify-email');

    // An address that does not exist must still yield the same generic result.
    await page.getByLabel('Email').fill(`nobody-${Date.now()}@e2e.test`);
    await page.getByRole('button', { name: 'Send verification link' }).click();

    await expect(page.getByTestId('verify-resend-sent')).toBeVisible();
  });
});
