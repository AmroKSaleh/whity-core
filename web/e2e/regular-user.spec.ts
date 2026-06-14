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

  test('an admin data page reached by direct URL degrades safely (data-layer 403)', async ({
    userPage,
    page,
  }) => {
    // DEFENCE-IN-DEPTH pin (complements the nav-absence test above). The
    // sidebar correctly hides the gated links now, but the admin route layout
    // still renders its children unconditionally, so a non-holder who reaches
    // an admin data page by TYPING the URL must be rejected at the DATA layer:
    // GET /api/roles returns 403 for the plain user, which the screen surfaces
    // as an empty table + an error toast. This guards against a regression
    // where /api/roles started returning 200 for a regular user — invisible to
    // the nav-absence test, which never loads the page. We go straight to the
    // page (NOT via the sidebar nav, which has no link to click) and assert the
    // exact degradation the old nav-based test pinned before the WC-175 flip.
    void userPage; // landed on the dashboard; we navigate by URL from here.
    await page.goto('/admin/roles');
    await page.waitForURL('**/admin/roles');
    await expect(page.getByRole('heading', { name: 'Roles' })).toBeVisible();
    await expect(page.getByText('No data available')).toBeVisible();
    await expect(page.getByText('Failed to fetch roles').first()).toBeVisible();

    // A second admin page proves the screen-layer 403 handling is not specific
    // to /api/roles: /api/users is likewise rejected for the plain user.
    await page.goto('/admin/users');
    await page.waitForURL('**/admin/users');
    await expect(page.getByRole('heading', { name: 'Users' })).toBeVisible();
    await expect(page.getByText('No data available')).toBeVisible();
    await expect(page.getByText('Failed to fetch users').first()).toBeVisible();
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
