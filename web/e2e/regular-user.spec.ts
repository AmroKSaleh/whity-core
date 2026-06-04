import { test, expect } from './support/fixtures';
import { REGULAR_USER } from './support/constants';

/**
 * Regular (non-admin) user flow.
 *
 * Empirically verified against the live app: the backend's /api/navigation
 * endpoint returns the SAME items for every role, so the sidebar is identical
 * for admins and regular users (there is no client-side role gate; the admin
 * route layout renders children unconditionally). Access control is enforced
 * purely at the data layer — admin-only endpoints return HTTP 403 for the
 * regular user, which the UI surfaces as an error toast plus an empty table.
 *
 * The flow therefore asserts the DATA-LEVEL difference rather than a reduced
 * navigation, which would not match actual behaviour.
 */
test.describe('Regular user (non-admin)', () => {
  test('can log in and reach the dashboard', async ({ userPage, page }) => {
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByRole('heading', { name: 'Welcome back!' })).toBeVisible();
    await userPage.shell.expectLoggedInAs(REGULAR_USER.email);
    // The dashboard reflects the user's role.
    await expect(page.getByText('User', { exact: true })).toBeVisible();
  });

  test('admin data pages return no data and surface a permission error', async ({
    userPage,
    page,
  }) => {
    // Navigating to an admin data page is allowed (no client gate), but the
    // data fetch is rejected by the backend (403) for a regular user.
    await userPage.shell.clickNav('Roles');
    await page.waitForURL('**/admin/roles');
    await expect(page.getByRole('heading', { name: 'Roles' })).toBeVisible();
    await expect(page.getByText('No data available')).toBeVisible();
    await expect(page.getByText('Failed to fetch roles').first()).toBeVisible();
  });

  test('users page also shows no data for a regular user', async ({ userPage, page }) => {
    await userPage.shell.clickNav('Users');
    await page.waitForURL('**/admin/users');
    await expect(page.getByText('No data available')).toBeVisible();
    await expect(page.getByText('Failed to fetch users').first()).toBeVisible();
  });

  test('settings page is accessible and shows the user role', async ({ userPage, page }) => {
    await userPage.shell.clickNav('Settings');
    await page.waitForURL('**/settings');
    await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();
    await expect(page.getByText(REGULAR_USER.email).first()).toBeVisible();
  });

  test('tenants and OUs pages also return no data for a regular user', async ({
    userPage,
    page,
  }) => {
    // Tenants: backend rejects the list fetch (403) -> empty table + error toast.
    await userPage.shell.clickNav('Tenants');
    await page.waitForURL('**/admin/tenants');
    await expect(page.getByRole('heading', { name: 'Tenants' })).toBeVisible();
    await expect(page.getByText('No data available')).toBeVisible();

    // Organizational Units: same data-layer rejection -> empty state.
    await userPage.shell.clickNav('Organizational Units');
    await page.waitForURL('**/admin/ous');
    await expect(
      page.getByRole('heading', { name: 'Organizational Units' }).first()
    ).toBeVisible();
    await expect(page.getByText('No organizational units yet')).toBeVisible();
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
