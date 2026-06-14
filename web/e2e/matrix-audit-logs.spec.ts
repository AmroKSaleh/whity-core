import { test, expect } from './support/fixtures';
import { uniqueSuffix } from './support/constants';

/**
 * WC-173 role matrix — Audit Logs admin area (/admin/audit-logs).
 *
 * LIVE-VERIFIED access map for GET /api/audit-logs:
 *   admin    -> 200 (role grant, core migration)
 *   user     -> 403
 *   delegate -> 200 (delegated audit:read)
 *
 * DENIAL SURFACE (current behavior, pinned here): unlike the delegations and
 * relations pages, this page has NO dedicated access-denied card — a 403 is
 * treated like any failed fetch, surfacing as a "Failed to fetch audit logs"
 * error toast over the regular "No audit entries found" empty state, while the
 * page chrome (filters + Refresh) still renders.
 */
test.describe('Audit Logs (role matrix)', () => {
  test('page surface follows the role access map', async ({
    roleSession,
    role,
    page,
  }) => {
    const listResponse = page.waitForResponse(
      (res) =>
        res.url().includes('/api/audit-logs') &&
        res.request().method() === 'GET'
    );
    await roleSession.shell.clickNav('Audit Logs');
    await page.waitForURL('**/admin/audit-logs');
    expect((await listResponse).status()).toBe(role === 'user' ? 403 : 200);

    await expect(
      page.getByRole('heading', { name: 'Audit Logs' })
    ).toBeVisible();
    // The chrome is not permission-gated; only the data call is.
    await expect(page.getByRole('button', { name: 'Refresh' })).toBeVisible();

    if (role === 'user') {
      await expect(
        page.getByText('Failed to fetch audit logs').first()
      ).toBeVisible();
      await expect(page.getByText('No audit entries found')).toBeVisible();
      await expect(
        page.getByRole('heading', { name: 'Access denied' })
      ).toHaveCount(0);
      return;
    }

    // admin (role grant) and delegate (delegated audit:read) read the trail.
    // A fresh database may legitimately hold zero entries, so pin "entries
    // table or empty state" rather than a row count.
    await expect(
      page.getByRole('table').or(page.getByText('No audit entries found'))
    ).toBeVisible();
  });

  test('admin can round-trip the action filter to the backend', async ({
    roleSession,
    role,
    page,
  }) => {
    test.skip(
      role !== 'admin',
      'The filter exercise needs guaranteed 200s; the per-role surface is pinned above.'
    );

    await roleSession.shell.clickNav('Audit Logs');
    await page.waitForURL('**/admin/audit-logs');
    await expect(
      page.getByRole('heading', { name: 'Audit Logs' })
    ).toBeVisible();

    // Filter on an action name that cannot exist: Apply must round-trip the
    // value to the API and deterministically produce the empty state, with no
    // dependence on what audit data the environment holds.
    const impossibleAction = `matrix.e2e.${uniqueSuffix()}`;
    await page
      .getByPlaceholder('e.g. auth.login.success')
      .fill(impossibleAction);
    const filtered = page.waitForResponse(
      (res) =>
        res.url().includes('/api/audit-logs') &&
        res.url().includes(`action=${impossibleAction}`)
    );
    await page.getByRole('button', { name: 'Apply' }).click();
    expect((await filtered).status()).toBe(200);
    await expect(page.getByText('No audit entries found')).toBeVisible();

    // Clear restores the unfiltered query (and whatever data it yields).
    const cleared = page.waitForResponse(
      (res) =>
        res.url().includes('/api/audit-logs') &&
        !res.url().includes('action=')
    );
    await page.getByRole('button', { name: 'Clear' }).click();
    expect((await cleared).status()).toBe(200);
    await expect(
      page.getByRole('table').or(page.getByText('No audit entries found'))
    ).toBeVisible();
  });
});
