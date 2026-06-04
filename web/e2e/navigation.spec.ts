import { test, expect } from './support/fixtures';
import { SIDEBAR_SECTIONS } from './support/constants';

test.describe('Sidebar navigation (admin)', () => {
  test('all expected sidebar sections are present', async ({ adminPage }) => {
    for (const section of SIDEBAR_SECTIONS) {
      await expect(
        adminPage.shell.sidebar.getByRole('link', { name: new RegExp(`${section.label}$`) })
      ).toBeVisible();
    }
    await expect(adminPage.shell.logoutButton).toBeVisible();
    await expect(adminPage.shell.sidebar.getByText('Logged in as')).toBeVisible();
  });

  test('Users section loads its page with heading and table', async ({ adminPage, page }) => {
    await adminPage.shell.clickNav('Users');
    await page.waitForURL('**/admin/users');
    await expect(page.getByRole('heading', { name: 'Users' })).toBeVisible();
    await expect(page.getByRole('table')).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Email' })).toBeVisible();
  });

  test('Roles section loads its page with heading, table and Create button', async ({
    adminPage,
    page,
  }) => {
    await adminPage.shell.clickNav('Roles');
    await page.waitForURL('**/admin/roles');
    await expect(page.getByRole('heading', { name: 'Roles' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Create Role' })).toBeVisible();
    await expect(page.getByRole('table')).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Permission Count' })).toBeVisible();
  });

  test('Organizational Units section loads its page', async ({ adminPage, page }) => {
    await adminPage.shell.clickNav('Organizational Units');
    await page.waitForURL('**/admin/ous');
    await expect(
      page.getByRole('heading', { name: 'Organizational Units' }).first()
    ).toBeVisible();
    await expect(page.getByRole('button', { name: /Create (the first )?OU/ })).toBeVisible();
  });

  test('Tenants section loads its page with heading and table', async ({ adminPage, page }) => {
    await adminPage.shell.clickNav('Tenants');
    await page.waitForURL('**/admin/tenants');
    await expect(page.getByRole('heading', { name: 'Tenants' })).toBeVisible();
    await expect(page.getByRole('table')).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Slug' })).toBeVisible();
  });

  test('Settings section loads its page', async ({ adminPage, page }) => {
    await adminPage.shell.clickNav('Settings');
    await page.waitForURL('**/settings');
    await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();
    await expect(page.getByText('Account Information')).toBeVisible();
    await expect(page.getByText('Security', { exact: true })).toBeVisible();
  });

  test('Dashboard section loads the admin landing page (redirects to stats)', async ({
    adminPage,
    page,
  }) => {
    // The "Dashboard" sidebar item points at /admin, which server-redirects to
    // /admin/stats (the System Statistics page).
    await adminPage.shell.clickNav('Dashboard');
    await page.waitForURL('**/admin/stats');
    await expect(page.getByRole('heading', { name: 'System Statistics' })).toBeVisible();
  });
});
