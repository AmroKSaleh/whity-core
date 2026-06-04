import { test, expect } from './support/fixtures';

/**
 * System Statistics page (/admin/stats — the "Dashboard" sidebar target).
 *
 * Read-only: it renders metric cards, a per-role breakdown, a Growth Trends
 * chart with a Users/Tenants tab switcher, and Database/System/Environment
 * info cards. These tests cover the page's only interactive element (the tabs)
 * plus that the live metrics render with real values, not the "--" placeholder.
 */
test.describe('System Statistics (admin)', () => {
  test('renders metric cards populated from the live backend', async ({ page }) => {
    await page.goto('/admin/stats');
    await expect(page.getByRole('heading', { name: 'System Statistics' })).toBeVisible();

    // The four headline cards are present.
    await expect(page.getByText('Total Users')).toBeVisible();
    await expect(page.getByText('Active Roles')).toBeVisible();
    await expect(page.getByText('Total Tenants')).toBeVisible();
    await expect(page.getByText('Permissions', { exact: true })).toBeVisible();

    // The "Permissions" card resolves to a concrete number from the live stats
    // endpoint (the seed exposes 17 permissions), proving the fetch succeeded
    // rather than leaving the "--" loading placeholder. Scope to the bold value
    // node of the Permissions card to avoid matching unrelated "17" substrings
    // (e.g. a "1.17 MB" database size elsewhere on the page).
    const permsValue = page
      .locator('div.text-2xl.font-bold')
      .filter({ hasText: /^17$/ });
    await expect(permsValue).toBeVisible();
  });

  test('Growth Trends tabs switch between Users and Tenants', async ({ page }) => {
    await page.goto('/admin/stats');
    await expect(page.getByText('Growth Trends')).toBeVisible();

    const usersTab = page.getByRole('tab', { name: 'Users' });
    const tenantsTab = page.getByRole('tab', { name: 'Tenants' });
    await expect(usersTab).toBeVisible();
    await expect(tenantsTab).toBeVisible();

    // Users is selected by default; clicking Tenants makes it the selected tab.
    await expect(usersTab).toHaveAttribute('data-state', 'active');
    await tenantsTab.click();
    await expect(tenantsTab).toHaveAttribute('data-state', 'active');
    await expect(usersTab).toHaveAttribute('data-state', 'inactive');

    // Switch back.
    await usersTab.click();
    await expect(usersTab).toHaveAttribute('data-state', 'active');
  });

  test('Users per Role breakdown lists the seeded roles', async ({ page }) => {
    await page.goto('/admin/stats');
    const breakdown = page
      .locator('div')
      .filter({ has: page.getByText('Users per Role') })
      .first();
    await expect(breakdown).toBeVisible();
    // The seed has admin + user roles with at least one user each.
    await expect(page.getByText(/\d+ users/).first()).toBeVisible();
  });
});
