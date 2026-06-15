import { test, expect } from './support/fixtures';
import { ADMIN, uniqueSuffix } from './support/constants';
import { toastWithText } from './support/pages';
import {
  createAuthedApi,
  deleteUser,
  ensureUser,
  revokeDelegationsFor,
} from './support/api';

/**
 * WC-173 role matrix — Delegations admin area (/admin/delegations).
 *
 * LIVE-VERIFIED access map (WC-175 #191: the nav link is RBAC-filtered on
 * requiredPermission delegation:manage, and GET /api/delegations enforces it):
 *   admin    -> link shown, 200 (full management UI)
 *   user     -> link HIDDEN (delegation:manage is admin-role-gated; the link is
 *               filtered out server-side, so the page is not reachable via nav)
 *   delegate -> link HIDDEN (delegation:manage is NOT in the delegated set —
 *               holding delegated permissions grants no access to the
 *               delegation management area itself)
 *
 * DATA HYGIENE: the admin lifecycle delegates to a THROWAWAY user created per
 * run — never to the seeded accounts and never to the delegate fixture account
 * (delegate@example.com's grants ARE the matrix fixture and must not be
 * touched). Cleanup revokes any leftover live grants and deletes the account.
 */

// Shared with the cleanup hook so a mid-test failure still gets cleaned up.
let throwawayUserId: number | null = null;

test.describe('Delegations (role matrix)', () => {
  test.afterEach(async ({ baseURL, role }) => {
    if (role !== 'admin' || throwawayUserId === null || baseURL === undefined) {
      return;
    }
    // Best-effort: revoke anything still live for the throwaway grantee, then
    // delete the account (revoked rows are list-hidden and harmless).
    const api = await createAuthedApi(baseURL, ADMIN);
    await revokeDelegationsFor(api, 'user', throwawayUserId);
    await deleteUser(api, throwawayUserId);
    await api.dispose();
    throwawayUserId = null;
  });

  test('page surface follows the role access map', async ({
    roleSession,
    role,
    page,
  }) => {
    if (role !== 'admin') {
      // user AND delegate: the Delegations nav link is filtered out server-side
      // (neither holds delegation:manage), so the page is not reachable from
      // the sidebar — the old in-page denied card surface is gone.
      await expect(roleSession.shell.navLink('Delegations')).toHaveCount(0);
      return;
    }

    const listResponse = page.waitForResponse(
      (res) =>
        res.url().includes('/api/v1/v1/delegations') &&
        res.request().method() === 'GET'
    );
    await roleSession.shell.clickNav('Delegations');
    await page.waitForURL('**/admin/delegations');
    expect((await listResponse).status()).toBe(200);

    await expect(
      page.getByRole('heading', { name: 'Delegations' })
    ).toBeVisible();
    await expect(
      page.getByRole('button', { name: 'Create Delegation' })
    ).toBeVisible();
    await expect(
      page.getByRole('heading', { name: 'Access denied' })
    ).toHaveCount(0);
  });

  test('admin can create a delegation and revoking removes it from the live list', async ({
    roleSession,
    role,
    page,
    baseURL,
  }) => {
    test.skip(
      role !== 'admin',
      'Admin-only lifecycle; the user/delegate denial surface is pinned above.'
    );
    if (baseURL === undefined) {
      throw new Error('baseURL is required for API-backed setup');
    }

    // Arrange a throwaway grantee account through the admin API.
    const granteeEmail = `matrix-delegation-${uniqueSuffix()}@example.com`;
    const api = await createAuthedApi(baseURL, ADMIN);
    try {
      throwawayUserId = await ensureUser(api, {
        email: granteeEmail,
        password: 'Matrix-e2e-pass1',
        role: 'user',
      });
    } finally {
      await api.dispose();
    }
    const granteeId = throwawayUserId;

    await roleSession.shell.clickNav('Delegations');
    await page.waitForURL('**/admin/delegations');
    await expect(
      page.getByRole('heading', { name: 'Delegations' })
    ).toBeVisible();

    // Open the create modal; its pickers load from /api/permissions,
    // /api/roles, /api/users and /api/ous before the form renders.
    await page.getByRole('button', { name: 'Create Delegation' }).click();
    const dialog = page.getByRole('dialog');
    await expect(
      dialog.getByText('Grant a subset of your own permissions')
    ).toBeVisible();
    await expect(dialog.getByText('Delegate to')).toBeVisible();

    // Grantee type: Role (default) -> User.
    await dialog.getByRole('combobox').first().click();
    await page.getByRole('option', { name: 'User', exact: true }).click();

    // Grantee: the throwaway user, listed by email.
    await dialog.getByRole('combobox').nth(1).click();
    await page.getByRole('option', { name: granteeEmail }).click();

    // One permission the admin grantor holds (the subset invariant is
    // enforced server-side; a non-held permission would 422).
    await dialog
      .locator('label')
      .filter({ hasText: /^relations:read$/ })
      .locator('input[type="checkbox"]')
      .check();

    await dialog.getByRole('button', { name: 'Create Delegation' }).click();
    await expect(
      toastWithText(page, 'Delegation created successfully')
    ).toBeVisible();

    // The refetched list shows the new row: grantee label, permission, Active.
    // The grantee id is anchored with a no-digit-follows lookahead — a row's
    // text content has no separators ("User #815Tenant-wide"), so \b would
    // not stop "User #81" from also matching "User #815".
    const row = page
      .getByRole('row')
      .filter({ hasText: new RegExp(`User #${granteeId}(?!\\d)`) });
    await expect(row).toBeVisible();
    await expect(row).toContainText('relations:read');
    await expect(row).toContainText('Active');

    // Revoke via the row action + confirmation modal.
    await row.getByRole('button', { name: 'Revoke' }).click();
    const revokeDialog = page.getByRole('dialog');
    await expect(
      revokeDialog.getByText('Are you sure you want to revoke this delegation?')
    ).toBeVisible();
    await revokeDialog
      .getByRole('button', { name: 'Revoke Delegation' })
      .click();
    await expect(
      toastWithText(page, 'Delegation revoked successfully')
    ).toBeVisible();

    // CURRENT BEHAVIOR: GET /api/delegations excludes revoked rows unless
    // includeRevoked is passed (the page never passes it), so after the
    // post-revoke refetch the row DISAPPEARS from the list — it does not stay
    // behind with a "Revoked" badge.
    await expect(row).toHaveCount(0);
  });
});
