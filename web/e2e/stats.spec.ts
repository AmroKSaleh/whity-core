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

    // Every headline card resolves to a concrete number from the live stats
    // endpoint, proving the fetch succeeded rather than leaving the "--"
    // placeholder (or the loading pulse). The bold value node is unique to the
    // four headline cards.
    const resolvedValues = page
      .locator('div.text-2xl.font-bold')
      .filter({ hasText: /^\d+$/ });
    await expect(resolvedValues).toHaveCount(4);

    // Regression floor for the Permissions card: the catalogue only grows as
    // plugin migrations seed permissions, so an EXACT count would encode one
    // environment's plugin set — but every environment carries at least the
    // 26 core + 2 HelloWorld permissions of a fresh database (dev stacks may
    // have more). A value below that floor means the stats endpoint regressed.
    const permissionsValue = page
      .locator('[data-slot="card"]', {
        has: page.getByText('Permissions', { exact: true }),
      })
      .locator('div.text-2xl.font-bold');
    const permissions = Number(await permissionsValue.innerText());
    expect(permissions).toBeGreaterThanOrEqual(28);
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
