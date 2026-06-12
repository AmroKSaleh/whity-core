import { test, expect } from './support/fixtures';

/**
 * WC-173 role matrix — core admin data areas sweep (Users / Roles / Tenants /
 * Organizational Units / System Statistics).
 *
 * Admin CRUD depth lives in the dedicated per-area specs (users.spec.ts,
 * roles.spec.ts, ous-*.spec.ts, stats.spec.ts) — the admin branch here only
 * pins the rendered surface (heading + primary control), while user AND
 * delegate pin the denial surface. The delegated set is relations:read +
 * audit:read + hello:view ONLY, so core admin data stays 403 for the delegate
 * exactly like the plain user (verified live).
 *
 * The denial idiom matches regular-user.spec.ts: navigation is allowed (no
 * client-side gate), the data fetch 403s, and the page shows its empty state
 * plus a fetch-error toast. The stats page is the outlier: it swallows the
 * failure silently and renders "--" placeholders (no toast, no denied card).
 */
test.describe('Core admin areas (role matrix)', () => {
  test('Users page renders for admin and denies data to user and delegate', async ({
    roleSession,
    role,
    page,
  }) => {
    await roleSession.shell.clickNav('Users');
    await page.waitForURL('**/admin/users');
    await expect(page.getByRole('heading', { name: 'Users' })).toBeVisible();

    if (role === 'admin') {
      await expect(
        page.getByRole('button', { name: 'Create User' })
      ).toBeVisible();
      await expect(page.getByRole('table')).toBeVisible();
    } else {
      await expect(page.getByText('No data available')).toBeVisible();
      await expect(page.getByText('Failed to fetch users').first()).toBeVisible();
    }
  });

  test('Roles page renders for admin and denies data to user and delegate', async ({
    roleSession,
    role,
    page,
  }) => {
    await roleSession.shell.clickNav('Roles');
    await page.waitForURL('**/admin/roles');
    await expect(page.getByRole('heading', { name: 'Roles' })).toBeVisible();

    if (role === 'admin') {
      await expect(
        page.getByRole('button', { name: 'Create Role' })
      ).toBeVisible();
      await expect(page.getByRole('table')).toBeVisible();
    } else {
      await expect(page.getByText('No data available')).toBeVisible();
      await expect(page.getByText('Failed to fetch roles').first()).toBeVisible();
    }
  });

  test('Tenants page renders for admin and denies data to user and delegate', async ({
    roleSession,
    role,
    page,
  }) => {
    await roleSession.shell.clickNav('Tenants');
    await page.waitForURL('**/admin/tenants');
    await expect(page.getByRole('heading', { name: 'Tenants' })).toBeVisible();

    if (role === 'admin') {
      await expect(
        page.getByRole('button', { name: 'Create Tenant' })
      ).toBeVisible();
      await expect(page.getByRole('table')).toBeVisible();
    } else {
      await expect(page.getByText('No data available')).toBeVisible();
      await expect(
        page.getByText('Failed to fetch tenants').first()
      ).toBeVisible();
    }
  });

  test('Organizational Units page renders for admin and denies data to user and delegate', async ({
    roleSession,
    role,
    page,
  }) => {
    await roleSession.shell.clickNav('Organizational Units');
    await page.waitForURL('**/admin/ous');
    await expect(
      page.getByRole('heading', { name: 'Organizational Units' }).first()
    ).toBeVisible();

    if (role === 'admin') {
      await expect(
        page.getByRole('button', { name: /Create (the first )?OU/ })
      ).toBeVisible();
    } else {
      // The 403 leaves the list empty; the page shows its empty state.
      await expect(page.getByText('No organizational units yet')).toBeVisible();
      await expect(
        page.getByText('Failed to fetch organizational units').first()
      ).toBeVisible();
    }
  });

  test('Stats page loads numbers for admin and silent placeholders for user and delegate', async ({
    roleSession,
    role,
    page,
  }) => {
    const statsResponse = page.waitForResponse((res) =>
      res.url().includes('/api/admin/stats')
    );
    // The "Dashboard" nav item points at /admin, which redirects to the
    // System Statistics page.
    await roleSession.shell.clickNav('Dashboard');
    await page.waitForURL('**/admin/stats');
    expect((await statsResponse).status()).toBe(role === 'admin' ? 200 : 403);

    await expect(
      page.getByRole('heading', { name: 'System Statistics' })
    ).toBeVisible();
    await expect(page.getByText('Total Users')).toBeVisible();

    if (role === 'admin') {
      // Real numbers replace the placeholders once the 200 payload lands.
      await expect(page.getByText('--')).toHaveCount(0);
    } else {
      // CURRENT BEHAVIOR: the 403 is swallowed silently (no toast, no denied
      // card) and every total keeps its "--" placeholder.
      await expect(page.getByText('--').first()).toBeVisible();
    }
  });
});
