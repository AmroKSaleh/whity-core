import { test, expect } from './support/fixtures';
import { LoginPage, AppShell } from './support/pages';
import { ADMIN, REGULAR_USER } from './support/constants';

/**
 * Auth/session TRANSITION coverage (WC-173). Runs in the `authflow` project
 * (no stored auth state) because every test exercises a transition the
 * storage-state projects can never reach: what the SPA shows BETWEEN
 * authentication states in one browser context — fresh-login feature
 * resolution, logout -> re-login role switches, and reload on a sub-page.
 *
 * auth.spec.ts owns the basic login/logout/redirect/reload-on-dashboard
 * behaviour; nothing here duplicates it.
 */
test.describe('Auth transitions', () => {
  test('admin login resolves the plugin screen via pure SPA navigation (WC-169)', async ({
    page,
  }) => {
    const login = new LoginPage(page);
    await login.loginExpectingSuccess(ADMIN);

    // WITHOUT any reload: the login itself must refresh the plugin feature
    // list, so the Greetings nav link appears and the feature id resolves.
    // (A stale pre-login fetch — the WC-169 staleness regression — would
    // leave the list empty and render "Feature unavailable" instead.)
    // NOTE: the dev stack may also carry an Announcements feature; assert on
    // Greetings only, which ships in the repo and exists in every environment.
    const shell = new AppShell(page);
    await shell.clickNav('Greetings');
    await page.waitForURL('**/admin/x/hello-greetings');

    // The schema-driven CRUD screen renders the feature label as its heading.
    await expect(page.getByRole('heading', { name: 'Greetings' })).toBeVisible();
    await expect(
      page.getByRole('heading', { name: 'Feature unavailable' })
    ).toHaveCount(0);
    await expect(page.getByText('provided by the HelloWorld plugin')).toBeVisible();
  });

  test('logout then user login in the same context swaps identity and data gating', async ({
    page,
  }) => {
    const login = new LoginPage(page);
    await login.loginExpectingSuccess(ADMIN);
    const shell = new AppShell(page);
    await shell.expectLoggedInAs(ADMIN.email);

    // Switch roles WITHOUT a fresh context: logout, then log in as the plain
    // user in the same SPA session.
    await shell.logout();
    await login.loginExpectingSuccess(REGULAR_USER);
    await shell.expectLoggedInAs(REGULAR_USER.email);

    // A permission-gated page must reflect the NEW session immediately: the
    // delegations page fails closed with its Access denied card for a user
    // without delegation:manage (the nav link itself is unfiltered, #191).
    await shell.clickNav('Delegations');
    await page.waitForURL('**/admin/delegations');
    await expect(
      page.getByRole('heading', { name: 'Access denied' })
    ).toBeVisible();
    await expect(page.getByText(/delegation:manage/)).toBeVisible();
    // No admin-only action may leak into the denied state.
    await expect(
      page.getByRole('button', { name: 'Create Delegation' })
    ).toHaveCount(0);
  });

  test('re-login as admin after a user session clears the stale denied state', async ({
    page,
  }) => {
    // Arrange the "denied" state first: a user session that has VISITED the
    // gated page, so any stale client cache would keep showing the denial.
    const login = new LoginPage(page);
    await login.loginExpectingSuccess(REGULAR_USER);
    const shell = new AppShell(page);
    await shell.clickNav('Delegations');
    await page.waitForURL('**/admin/delegations');
    await expect(
      page.getByRole('heading', { name: 'Access denied' })
    ).toBeVisible();

    // Role switch back to admin in the same context.
    await shell.logout();
    await login.loginExpectingSuccess(ADMIN);
    await shell.clickNav('Delegations');
    await page.waitForURL('**/admin/delegations');

    // The admin view (header action + table or first-delegation empty state)
    // must replace the denial — no stale forbidden state may survive the
    // role switch. "Create Delegation" only renders in the non-denied view.
    await expect(
      page.getByRole('button', { name: 'Create Delegation' })
    ).toBeVisible();
    await expect(
      page.getByRole('heading', { name: 'Access denied' })
    ).toHaveCount(0);
  });

  test('the session survives a reload on a sub-page reached by SPA navigation', async ({
    page,
  }) => {
    // auth.spec.ts covers reload on /dashboard right after login; this covers
    // the deeper case — reload on an admin SUB-PAGE the SPA navigated to.
    const login = new LoginPage(page);
    await login.loginExpectingSuccess(ADMIN);
    const shell = new AppShell(page);
    await shell.clickNav('Users');
    await page.waitForURL('**/admin/users');
    await expect(page.getByRole('heading', { name: 'Users' })).toBeVisible();

    await page.reload();

    // The session re-hydrates from the httpOnly cookie and stays put.
    await expect(page).toHaveURL(/\/admin\/users$/);
    await expect(page.getByRole('heading', { name: 'Users' })).toBeVisible();
    await expect(page.getByRole('table')).toBeVisible();
    await new AppShell(page).expectLoggedInAs(ADMIN.email);
  });
});
