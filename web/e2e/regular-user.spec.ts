import { test, expect } from './support/fixtures';
import { REGULAR_USER } from './support/constants';

/**
 * Regular (non-admin) user flow.
 *
 * Verified live against the new RBAC-filtered navigation (WC-175 #191):
 * `GET /api/navigation` is now filtered server-side per caller, so the regular
 * user's sidebar shows ONLY "Settings" (every other section is gated on a
 * permission/role the plain user lacks). The admin-only data pages are no
 * longer reachable from the sidebar at all — there is no link to click — so
 * this flow asserts the NAV-LEVEL difference (gated links absent) instead of
 * the old data-layer 403 surface.
 */
test.describe('Regular user (non-admin)', () => {
  test('can log in and reach the dashboard', async ({ userPage, page }) => {
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByRole('heading', { name: 'Welcome back!' })).toBeVisible();
    await userPage.shell.expectLoggedInAs(REGULAR_USER.email);
    // The dashboard reflects the user's role.
    await expect(page.getByText('User', { exact: true })).toBeVisible();
  });

  test('the sidebar hides every gated section and shows only Settings', async ({
    userPage,
    page,
  }) => {
    await expect(page).toHaveURL(/\/dashboard$/);

    // Every gated nav link is filtered out server-side for the plain user
    // (no role grant, no delegated permission), so none render in the sidebar.
    const hidden = [
      'Dashboard',
      'Users',
      'Roles',
      'Organizational Units',
      'Tenants',
      'Delegations',
      'Audit Logs',
      'Family Relations',
      'Greetings',
    ];
    for (const label of hidden) {
      await expect(userPage.shell.navLink(label)).toHaveCount(0);
    }

    // Settings is ungated and always present, alongside the shell chrome.
    await expect(userPage.shell.navLink('Settings')).toBeVisible();
    await expect(userPage.shell.logoutButton).toBeVisible();
    await expect(userPage.shell.sidebar.getByText('Logged in as')).toBeVisible();
  });

  test('settings page is accessible and shows the user role', async ({ userPage, page }) => {
    await userPage.shell.clickNav('Settings');
    await page.waitForURL('**/settings');
    await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();
    await expect(page.getByText(REGULAR_USER.email).first()).toBeVisible();
  });

  test('a regular user can enable 2FA setup on their own account (own-account scope)', async ({
    userPage,
    page,
  }) => {
    // 2FA is a self-service, own-account action — it is NOT admin-gated. The
    // regular user can open the setup wizard from their Settings page. We open
    // and cancel it (no confirm) so the user's account is never actually
    // enrolled, keeping user@example.com a plain-login account for other specs.
    await userPage.shell.clickNav('Settings');
    await page.waitForURL('**/settings');

    const enable = page.getByRole('button', { name: 'Enable 2FA' });
    await expect(enable).toBeVisible();
    await enable.click();

    const wizard = page.getByRole('dialog');
    await expect(wizard.getByText('Enable Two-Factor Authentication')).toBeVisible();
    // Cancel without confirming — do not enrol the seeded user account.
    await wizard.getByRole('button', { name: 'Cancel' }).click();
    await expect(wizard).toBeHidden();
  });

  test('the dashboard reports the user id, role badge and email', async ({
    userPage,
    page,
  }) => {
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByText('Your User ID')).toBeVisible();
    await expect(page.getByText('Your Role')).toBeVisible();
    // The role badge renders the capitalised role.
    await expect(page.getByText('User', { exact: true })).toBeVisible();
    await expect(page.getByText(REGULAR_USER.email).first()).toBeVisible();
    // The footer also reflects the logged-in regular user.
    await userPage.shell.expectLoggedInAs(REGULAR_USER.email);
  });
});
